<?php

namespace App\Http\Controllers\Api\Infrastructure;

use App\Enums\ResourceStatus;
use App\Enums\ServerConnectionType;
use App\Http\Resources\ServerResource;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

/**
 * @extends ProjectResourceController<Server>
 */
class ServerController extends ProjectResourceController
{
    protected function relation(Project $project): HasMany
    {
        return $project->servers();
    }

    protected function modelClass(): string
    {
        return Server::class;
    }

    protected function resourceClass(): string
    {
        return ServerResource::class;
    }

    protected function auditName(): string
    {
        return 'server';
    }

    protected function rules(Project $project, bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'environment_id' => ['bail', 'nullable', 'uuid', $this->sameProject('environments', $project)],
            'hostname' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'],
            'provider' => ['nullable', 'string', 'max:100'],
            'os' => ['nullable', 'string', 'max:100'],
            'connection_type' => ['sometimes', Rule::enum(ServerConnectionType::class)],
            'status' => ['sometimes', Rule::enum(ResourceStatus::class)],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
