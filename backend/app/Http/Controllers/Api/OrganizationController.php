<?php

namespace App\Http\Controllers\Api;

use App\Enums\RiskLevel;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\Audit\AuditLogger;
use App\Support\Rbac\Permission;
use App\Support\Rbac\Role;
use App\Support\Slug;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CurrentOrganization $tenant,
    ) {}

    /**
     * Organizations the authenticated user belongs to.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizations = $request->user()->organizations()
            ->withCount(['members', 'projects'])
            ->orderBy('name')
            ->get();

        return OrganizationResource::collection($organizations);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $user = $request->user();

        [$organization, $membership] = DB::transaction(function () use ($data, $user) {
            $organization = new Organization([
                'name' => $data['name'],
                'slug' => Slug::unique($data['name'], fn (string $slug) => Organization::where('slug', $slug)->exists()),
            ]);
            $organization->created_by = $user->id;
            $organization->save();

            $membership = $organization->members()->create([
                'user_id' => $user->id,
                'role' => Role::Owner,
            ]);

            return [$organization, $membership];
        });

        $this->audit->record('organization.created', $organization, organizationId: $organization->id);

        return (new OrganizationResource($organization))
            ->withMembership($membership)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * The organization resolved from the X-Organization-Id header.
     */
    public function current(): OrganizationResource
    {
        Gate::authorize(Permission::OrganizationView->value);

        $organization = $this->tenant->get()->loadCount(['members', 'projects']);

        return (new OrganizationResource($organization))->withMembership($this->tenant->membership());
    }

    public function update(Request $request): OrganizationResource
    {
        Gate::authorize(Permission::OrganizationUpdate->value);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'settings' => ['sometimes', 'array'],
        ]);

        $organization = $this->tenant->get();
        $organization->fill($data)->save();

        $this->audit->record('organization.updated', $organization, ['changes' => array_keys($organization->getChanges())]);

        return (new OrganizationResource($organization))->withMembership($this->tenant->membership());
    }

    /**
     * Deleting an organization cascades to every project and resource it owns, so
     * the caller must repeat the organization slug to confirm.
     */
    public function destroy(Request $request): JsonResponse
    {
        Gate::authorize(Permission::OrganizationDelete->value);

        $organization = $this->tenant->get();

        $request->validate(['confirm' => ['required', 'string']]);

        if ($request->string('confirm')->toString() !== $organization->slug) {
            throw ValidationException::withMessages([
                'confirm' => 'Type the organization slug to confirm deletion.',
            ]);
        }

        $this->audit->record('organization.deleted', $organization, ['name' => $organization->name], risk: RiskLevel::Critical);
        $organization->delete();

        return response()->json(['message' => 'Organization deleted.']);
    }
}
