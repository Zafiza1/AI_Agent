<?php

namespace App\Http\Controllers\Api\Infrastructure;

use App\Enums\ResourceStatus;
use App\Enums\ServiceRuntime;
use App\Enums\ServiceType;
use App\Http\Resources\ServiceResource;
use App\Models\Project;
use App\Models\Service;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

/**
 * @extends ProjectResourceController<Service>
 */
class ServiceController extends ProjectResourceController
{
    protected function relation(Project $project): HasMany
    {
        return $project->services();
    }

    protected function modelClass(): string
    {
        return Service::class;
    }

    protected function resourceClass(): string
    {
        return ServiceResource::class;
    }

    protected function auditName(): string
    {
        return 'service';
    }

    protected function rules(Project $project, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'type' => [$required, Rule::enum(ServiceType::class)],
            'runtime' => ['sometimes', Rule::enum(ServiceRuntime::class)],
            'environment_id' => ['bail', 'nullable', 'uuid', $this->sameProject('environments', $project)],
            'server_id' => ['bail', 'nullable', 'uuid', $this->sameProject('servers', $project)],
            'container_name' => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'health_check_url' => ['nullable', 'url:http,https', 'max:500'],
            'status' => ['sometimes', Rule::enum(ResourceStatus::class)],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
