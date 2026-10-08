<?php

namespace App\Http\Controllers\Api;

use App\Enums\DatabaseEngine;
use App\Enums\EnvironmentType;
use App\Enums\ProjectStatus;
use App\Enums\RepositoryProvider;
use App\Enums\ResourceStatus;
use App\Enums\ServerConnectionType;
use App\Enums\ServiceRuntime;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Support\Rbac\Permission;
use App\Support\Rbac\Role;
use Illuminate\Http\JsonResponse;

/**
 * Enumerations and the role/permission matrix, so clients never hardcode them.
 */
class MetaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $values = fn (string $enum) => array_column($enum::cases(), 'value');

        return response()->json([
            'data' => [
                'roles' => array_map(fn (Role $role) => [
                    'value' => $role->value,
                    'permissions' => array_map(fn (Permission $p) => $p->value, $role->permissions()),
                ], Role::cases()),
                'permissions' => $values(Permission::class),
                'enums' => [
                    'project_status' => $values(ProjectStatus::class),
                    'repository_provider' => $values(RepositoryProvider::class),
                    'environment_type' => $values(EnvironmentType::class),
                    'database_engine' => $values(DatabaseEngine::class),
                    'service_type' => $values(ServiceType::class),
                    'service_runtime' => $values(ServiceRuntime::class),
                    'server_connection_type' => $values(ServerConnectionType::class),
                    'resource_status' => $values(ResourceStatus::class),
                ],
            ],
        ]);
    }
}
