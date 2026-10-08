<?php

namespace App\Http\Controllers\Api\Infrastructure;

use App\Enums\DatabaseEngine;
use App\Enums\ResourceStatus;
use App\Http\Resources\DatabaseInstanceResource;
use App\Models\DatabaseInstance;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

/**
 * @extends ProjectResourceController<DatabaseInstance>
 */
class DatabaseController extends ProjectResourceController
{
    protected function relation(Project $project): HasMany
    {
        return $project->databases();
    }

    protected function modelClass(): string
    {
        return DatabaseInstance::class;
    }

    protected function resourceClass(): string
    {
        return DatabaseInstanceResource::class;
    }

    protected function auditName(): string
    {
        return 'database';
    }

    protected function rules(Project $project, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'engine' => [$required, Rule::enum(DatabaseEngine::class)],
            'environment_id' => ['bail', 'nullable', 'uuid', $this->sameProject('environments', $project)],
            'server_id' => ['bail', 'nullable', 'uuid', $this->sameProject('servers', $project)],
            'version' => ['nullable', 'string', 'max:50'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'database_name' => ['nullable', 'string', 'max:128'],
            'status' => ['sometimes', Rule::enum(ResourceStatus::class)],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
