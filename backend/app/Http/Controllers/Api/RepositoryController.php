<?php

namespace App\Http\Controllers\Api;

use App\Enums\RepositoryConnectionStatus;
use App\Enums\RepositoryProvider;
use App\Http\Controllers\Controller;
use App\Http\Resources\RepositoryResource;
use App\Models\Project;
use App\Models\Repository;
use App\Services\Audit\AuditLogger;
use App\Services\Git\RepositoryLinker;
use App\Support\Rbac\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Repository registry. Provider operations (connect, branches, commits, pull
 * requests) live in Git\RepositoryGitController.
 */
class RepositoryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize(Permission::ProjectsView->value);

        return RepositoryResource::collection(
            $project->repositories()->with('gitConnection')->withCount('openPullRequests')
                ->orderByDesc('is_primary')->orderBy('url')->get()
        );
    }

    /**
     * Every repository of the organization, across projects.
     */
    public function all(Request $request): AnonymousResourceCollection
    {
        Gate::authorize(Permission::ProjectsView->value);

        $filters = $request->validate([
            'connection_status' => ['nullable', Rule::enum(RepositoryConnectionStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return RepositoryResource::collection(
            Repository::query()
                ->with(['gitConnection', 'project'])
                ->withCount('openPullRequests')
                ->whereHas('project')
                ->when($filters['connection_status'] ?? null, fn ($q, $status) => $q->where('connection_status', $status))
                ->when($filters['search'] ?? null, fn ($q, $search) => $q->whereRaw('lower(coalesce(full_name, url)) like ?', ['%'.mb_strtolower($search).'%']))
                ->orderBy('full_name')
                ->paginate(50)
        );
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize(Permission::ProjectsUpdate->value);

        $data = $request->validate([
            'url' => ['required', 'url:https,http,ssh', 'max:500', Rule::unique('repositories')->where('project_id', $project->id)],
            'provider' => ['nullable', Rule::enum(RepositoryProvider::class)],
            'default_branch' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $repository = DB::transaction(function () use ($project, $data) {
            $isPrimary = ($data['is_primary'] ?? false) || ! $project->repositories()->exists();

            if ($isPrimary) {
                $project->repositories()->update(['is_primary' => false]);
            }

            $repository = new Repository([
                'url' => $data['url'],
                'provider' => $data['provider'] ?? RepositoryProvider::fromUrl($data['url'])->value,
                'full_name' => Repository::fullNameFromUrl($data['url']),
                'default_branch' => $data['default_branch'] ?? 'main',
                'is_primary' => $isPrimary,
            ]);
            $repository->project()->associate($project);
            $repository->save();

            return $repository;
        });

        $this->audit->record('repository.created', $repository, ['url' => $repository->url]);

        return (new RepositoryResource($repository))->response()->setStatusCode(201);
    }

    public function update(Request $request, Repository $repository): RepositoryResource
    {
        Gate::authorize(Permission::ProjectsUpdate->value);

        $data = $request->validate([
            'default_branch' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'is_primary' => ['sometimes', 'accepted'],
        ]);

        DB::transaction(function () use ($repository, $data) {
            if (! empty($data['is_primary'])) {
                Repository::where('project_id', $repository->project_id)->whereKeyNot($repository->id)->update(['is_primary' => false]);
                $repository->is_primary = true;
            }

            if (isset($data['default_branch'])) {
                $repository->default_branch = $data['default_branch'];
            }

            $repository->save();
        });

        $this->audit->record('repository.updated', $repository, ['fields' => array_keys($data)]);

        return new RepositoryResource($repository);
    }

    public function destroy(Repository $repository, RepositoryLinker $linker): JsonResponse
    {
        Gate::authorize(Permission::ProjectsUpdate->value);

        // Remove the platform's webhook from the provider before forgetting the repository.
        if ($repository->git_connection_id) {
            $linker->disconnect($repository);
        }

        $repository->delete();
        $this->audit->record('repository.deleted', $repository, ['url' => $repository->url]);

        return response()->json(['message' => 'Repository removed.']);
    }
}
