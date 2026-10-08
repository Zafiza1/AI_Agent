<?php

namespace App\Http\Controllers\Api;

use App\Enums\EnvironmentType;
use App\Enums\RiskLevel;
use App\Http\Controllers\Controller;
use App\Http\Resources\EnvironmentResource;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Audit\AuditLogger;
use App\Services\Projects\ProjectRegistrar;
use App\Support\Rbac\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Protected environments (production by default) can only be created, changed or
 * removed by members holding environments.manage_protected. The same permission
 * is required to change the protection flags themselves.
 */
class EnvironmentController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ProjectRegistrar $registrar,
    ) {}

    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize(Permission::ProjectsView->value);

        $environments = $project->environments()
            ->withCount('variables')
            ->get()
            ->sortBy(fn (Environment $e) => array_search($e->type, EnvironmentType::cases(), true).$e->name)
            ->values();

        return EnvironmentResource::collection($environments);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('environments')->where('project_id', $project->id)],
            'type' => ['required', Rule::enum(EnvironmentType::class)],
            ...$this->attributeRules(),
        ]);

        $type = EnvironmentType::from($data['type']);
        $protected = $data['is_protected'] ?? $type->isProtectedByDefault();

        Gate::authorize(($protected ? Permission::EnvironmentsManageProtected : Permission::EnvironmentsManage)->value);

        $environment = $this->registrar->createEnvironment($project, $data['name'], $type, array_diff_key($data, array_flip(['name', 'type'])));

        $this->audit->record('environment.created', $environment, ['name' => $environment->name, 'type' => $type->value]);

        return (new EnvironmentResource($environment))->response()->setStatusCode(201);
    }

    public function show(Environment $environment): EnvironmentResource
    {
        Gate::authorize(Permission::ProjectsView->value);

        return new EnvironmentResource($environment->loadCount('variables'));
    }

    public function update(Request $request, Environment $environment): EnvironmentResource
    {
        Gate::authorize($environment->managePermission()->value);

        $data = $request->validate($this->attributeRules());

        $touchesProtection = collect(['is_protected', 'requires_approval'])
            ->contains(fn (string $flag) => array_key_exists($flag, $data) && (bool) $data[$flag] !== $environment->{$flag});

        if ($touchesProtection) {
            Gate::authorize(Permission::EnvironmentsManageProtected->value);
        }

        $environment->fill($data)->save();

        $this->audit->record(
            'environment.updated',
            $environment,
            ['fields' => array_keys($environment->getChanges())],
            risk: $touchesProtection ? RiskLevel::High : null,
        );

        return new EnvironmentResource($environment->loadCount('variables'));
    }

    public function destroy(Environment $environment): JsonResponse
    {
        Gate::authorize($environment->managePermission()->value);

        $environment->delete();
        $this->audit->record('environment.deleted', $environment, ['name' => $environment->name], risk: RiskLevel::High);

        return response()->json(['message' => 'Environment deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributeRules(): array
    {
        return [
            'url' => ['nullable', 'url:http,https', 'max:500'],
            'branch' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'health_check_url' => ['nullable', 'url:http,https', 'max:500'],
            'is_protected' => ['sometimes', 'boolean'],
            'requires_approval' => ['sometimes', 'boolean'],
            'deployment_config' => ['nullable', 'array'],
        ];
    }
}
