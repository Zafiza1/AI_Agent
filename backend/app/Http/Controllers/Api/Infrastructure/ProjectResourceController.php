<?php

namespace App\Http\Controllers\Api\Infrastructure;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Audit\AuditLogger;
use App\Support\Rbac\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared CRUD for infrastructure records that belong to a project (servers,
 * databases, services). These are registry entries describing infrastructure;
 * operating on the infrastructure itself goes through the Tool Gateway (Phase 3+).
 *
 * @template TModel of Model
 */
abstract class ProjectResourceController extends Controller
{
    public function __construct(protected readonly AuditLogger $audit) {}

    /** @return HasMany<TModel, Project> */
    abstract protected function relation(Project $project): HasMany;

    /** @return class-string<TModel> */
    abstract protected function modelClass(): string;

    /** @return class-string<JsonResource> */
    abstract protected function resourceClass(): string;

    /** Audit action prefix, e.g. "server". */
    abstract protected function auditName(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function rules(Project $project, bool $creating): array;

    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize(Permission::ProjectsView->value);

        return $this->resourceClass()::collection($this->relation($project)->orderBy('name')->get());
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize(Permission::InfrastructureManage->value);

        $data = $request->validate($this->rules($project, creating: true));

        /** @var TModel $model */
        $model = $this->relation($project)->make($data);
        $model->setAttribute('organization_id', $project->organization_id);
        $model->save();

        $this->audit->record($this->auditName().'.created', $model, ['name' => $model->getAttribute('name')]);

        return (new ($this->resourceClass())($model))->response()->setStatusCode(201);
    }

    public function show(string $id): JsonResource
    {
        Gate::authorize(Permission::ProjectsView->value);

        return new ($this->resourceClass())($this->find($id));
    }

    public function update(Request $request, string $id): JsonResource
    {
        Gate::authorize(Permission::InfrastructureManage->value);

        $model = $this->find($id);
        $project = Project::findOrFail($model->getAttribute('project_id'));
        $data = $request->validate($this->rules($project, creating: false));

        $model->fill($data)->save();
        $this->audit->record($this->auditName().'.updated', $model, ['fields' => array_keys($model->getChanges())]);

        return new ($this->resourceClass())($model);
    }

    public function destroy(string $id): JsonResponse
    {
        Gate::authorize(Permission::InfrastructureManage->value);

        $model = $this->find($id);
        $model->delete();
        $this->audit->record($this->auditName().'.deleted', $model, ['name' => $model->getAttribute('name')]);

        return response()->json(['message' => ucfirst($this->auditName()).' deleted.']);
    }

    /**
     * @return TModel
     */
    protected function find(string $id): Model
    {
        // Tenant global scope applies: other organizations' records are not found.
        return $this->modelClass()::query()->findOrFail($id);
    }

    /**
     * An id of a sibling record that must belong to the same project. Use after
     * "bail" and "uuid" so malformed ids never reach PostgreSQL's uuid parser.
     */
    protected function sameProject(string $table, Project $project): Exists
    {
        return Rule::exists($table, 'id')->where('project_id', $project->id);
    }
}
