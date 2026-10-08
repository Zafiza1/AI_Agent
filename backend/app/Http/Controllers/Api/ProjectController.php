<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectStatus;
use App\Enums\RepositoryProvider;
use App\Enums\RiskLevel;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\Audit\AuditLogger;
use App\Services\Projects\ProjectRegistrar;
use App\Support\Rbac\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    private const COUNTS = ['environments', 'repositories', 'servers', 'databases', 'services'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ProjectRegistrar $registrar,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize(Permission::ProjectsView->value);

        $request->validate([
            'status' => ['nullable', Rule::enum(ProjectStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $projects = Project::query()
            ->with('primaryRepository')
            ->withCount(self::COUNTS)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('search'), function ($q, $search) {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(description) like ?', [$like]));
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return ProjectResource::collection($projects);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ProjectsCreate->value);

        $data = $request->validate($this->rules(creating: true));
        $project = $this->registrar->create($data, $request->user());

        $this->audit->record('project.created', $project, ['name' => $project->name]);

        return (new ProjectResource($this->loadDetail($project)))->response()->setStatusCode(201);
    }

    public function show(Project $project): ProjectResource
    {
        Gate::authorize(Permission::ProjectsView->value);

        return new ProjectResource($this->loadDetail($project));
    }

    public function update(Request $request, Project $project): ProjectResource
    {
        Gate::authorize(Permission::ProjectsUpdate->value);

        $data = $request->validate($this->rules(creating: false));
        $this->registrar->update($project, $data);

        $this->audit->record('project.updated', $project, ['fields' => array_keys($data)]);

        return new ProjectResource($this->loadDetail($project));
    }

    public function destroy(Project $project): JsonResponse
    {
        Gate::authorize(Permission::ProjectsDelete->value);

        $project->delete();
        $this->audit->record('project.deleted', $project, ['name' => $project->name], risk: RiskLevel::High);

        return response()->json(['message' => 'Project deleted.']);
    }

    private function loadDetail(Project $project): Project
    {
        return $project->load(['primaryRepository', 'environments', 'creator'])->loadCount(self::COUNTS);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'repository_url' => ['nullable', 'url:https,http,ssh', 'max:500'],
            'repository_provider' => ['nullable', Rule::enum(RepositoryProvider::class)],
            'default_branch' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'framework' => ['nullable', 'string', 'max:100'],
            'language' => ['nullable', 'string', 'max:100'],
            'database_type' => ['nullable', 'string', 'max:100'],
            'deployment_type' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::enum(ProjectStatus::class)],
            'ai_context' => ['nullable', 'array'],
            'ai_context.notes' => ['nullable', 'string', 'max:5000'],
            'create_default_environments' => ['sometimes', 'boolean'],
        ];
    }
}
