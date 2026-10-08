<?php

namespace App\Http\Controllers\Api;

use App\Enums\RiskLevel;
use App\Http\Controllers\Controller;
use App\Http\Resources\MemberResource;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Rbac\Permission;
use App\Support\Rbac\Role;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Membership management for the current organization.
 *
 * Rules: only owners grant or modify ownership, and an organization always keeps
 * at least one owner. Members are added by the email of an existing account;
 * email invitations are planned for a later phase.
 */
class MemberController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CurrentOrganization $tenant,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize(Permission::MembersView->value);

        $members = $this->tenant->get()->members()->with('user')->orderBy('created_at')->get();

        return MemberResource::collection($members);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::MembersManage->value);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::enum(Role::class)],
        ]);

        $role = Role::from($data['role']);
        $this->ensureCanManageRole($role);

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => 'No account exists for this email. Ask the person to register first.',
            ]);
        }

        $organization = $this->tenant->get();

        if ($organization->members()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'This user is already a member.']);
        }

        $member = $organization->members()->create(['user_id' => $user->id, 'role' => $role]);
        $member->load('user');

        $this->audit->record('member.added', $member, ['user_id' => $user->id, 'role' => $role->value], risk: RiskLevel::Medium);

        return (new MemberResource($member))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $member): MemberResource
    {
        Gate::authorize(Permission::MembersManage->value);

        $member = $this->findMember($member);
        $data = $request->validate(['role' => ['required', Rule::enum(Role::class)]]);
        $newRole = Role::from($data['role']);

        $this->ensureCanManageRole($member->role);
        $this->ensureCanManageRole($newRole);

        if ($member->role === Role::Owner && $newRole !== Role::Owner) {
            $this->ensureAnotherOwnerExists($member);
        }

        $previous = $member->role;
        $member->update(['role' => $newRole]);

        $this->audit->record('member.role_changed', $member, [
            'user_id' => $member->user_id,
            'from' => $previous->value,
            'to' => $newRole->value,
        ], risk: RiskLevel::Medium);

        return new MemberResource($member->load('user'));
    }

    public function destroy(Request $request, string $member): JsonResponse
    {
        $member = $this->findMember($member);
        $isSelf = $member->user_id === $request->user()->id;

        // Anyone may leave; removing someone else requires members.manage.
        if (! $isSelf) {
            Gate::authorize(Permission::MembersManage->value);
            $this->ensureCanManageRole($member->role);
        }

        if ($member->role === Role::Owner) {
            $this->ensureAnotherOwnerExists($member);
        }

        $member->delete();
        $this->audit->record($isSelf ? 'member.left' : 'member.removed', $member, ['user_id' => $member->user_id], risk: RiskLevel::Medium);

        return response()->json(['message' => 'Member removed.']);
    }

    private function findMember(string $id): OrganizationMember
    {
        return $this->tenant->get()->members()->whereKey($id)->firstOrFail();
    }

    private function ensureCanManageRole(Role $target): void
    {
        if (! $this->tenant->role()?->canManageRole($target)) {
            throw new AuthorizationException('Only owners can grant or modify the owner role.');
        }
    }

    private function ensureAnotherOwnerExists(OrganizationMember $member): void
    {
        $otherOwners = $this->tenant->get()->members()
            ->where('role', Role::Owner->value)
            ->whereKeyNot($member->getKey())
            ->exists();

        if (! $otherOwners) {
            throw ValidationException::withMessages([
                'role' => 'An organization must keep at least one owner.',
            ]);
        }
    }
}
