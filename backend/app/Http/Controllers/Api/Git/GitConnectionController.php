<?php

namespace App\Http\Controllers\Api\Git;

use App\Enums\GitConnectionAuthType;
use App\Enums\RepositoryProvider;
use App\Enums\RiskLevel;
use App\Http\Controllers\Controller;
use App\Http\Resources\GitConnectionResource;
use App\Models\GitConnection;
use App\Services\Audit\AuditLogger;
use App\Services\Git\GitConnectionService;
use App\Services\Git\GitProviderFactory;
use App\Support\Rbac\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Git provider connections of the current organization.
 */
class GitConnectionController extends Controller
{
    public function __construct(
        private readonly GitConnectionService $connections,
        private readonly GitProviderFactory $providers,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize(Permission::OrganizationView->value);

        return GitConnectionResource::collection(
            GitConnection::query()->with('creator')->withCount('repositories')->latest()->get()
        );
    }

    /**
     * Connect with a personal access token. The token is verified against the
     * provider first, stored encrypted, and never returned.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::IntegrationsManage->value);

        $data = $request->validate([
            'provider' => ['required', Rule::in([RepositoryProvider::GitHub->value])],
            'auth_type' => ['sometimes', Rule::in([GitConnectionAuthType::PersonalAccessToken->value])],
            'name' => ['nullable', 'string', 'max:100'],
            'token' => ['required', 'string', 'min:20', 'max:255', 'regex:/^[A-Za-z0-9_]+$/'],
        ]);

        $connection = $this->connections->createFromToken(
            RepositoryProvider::from($data['provider']),
            $data['token'],
            $data['name'] ?? null,
            $request->user(),
        );

        $this->audit->record('git_connection.created', $connection, [
            'provider' => $connection->provider->value,
            'auth_type' => $connection->auth_type->value,
            'account' => $connection->account_login,
        ], risk: RiskLevel::Medium);

        return (new GitConnectionResource($connection->loadCount('repositories')))->response()->setStatusCode(201);
    }

    public function verify(GitConnection $connection): GitConnectionResource
    {
        Gate::authorize(Permission::IntegrationsManage->value);

        $this->connections->verify($connection);
        $this->audit->record('git_connection.verified', $connection, ['status' => $connection->status->value]);

        return new GitConnectionResource($connection->loadCount('repositories'));
    }

    public function update(Request $request, GitConnection $connection): GitConnectionResource
    {
        Gate::authorize(Permission::IntegrationsManage->value);

        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $connection->update($data);
        $this->audit->record('git_connection.updated', $connection, ['name' => $connection->name]);

        return new GitConnectionResource($connection->loadCount('repositories'));
    }

    public function destroy(GitConnection $connection): JsonResponse
    {
        Gate::authorize(Permission::IntegrationsManage->value);

        $repositories = $connection->repositories()->count();
        $this->connections->remove($connection);

        $this->audit->record('git_connection.deleted', $connection, [
            'provider' => $connection->provider->value,
            'account' => $connection->account_login,
            'repositories_unlinked' => $repositories,
        ], risk: RiskLevel::Medium);

        return response()->json(['message' => 'Connection removed.']);
    }

    /**
     * Repositories the connection can access, to pick from when linking.
     */
    public function remoteRepositories(GitConnection $connection): JsonResponse
    {
        Gate::authorize(Permission::ProjectsUpdate->value);

        $repositories = $this->providers->for($connection)->listRepositories();

        return response()->json(['data' => array_map(fn ($r) => $r->toArray(), $repositories)]);
    }
}
