<?php

namespace App\Http\Controllers\Api;

use App\Enums\ResourceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\DatabaseInstance;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Support\Rbac\Permission;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function overview(CurrentOrganization $tenant): JsonResponse
    {
        Gate::authorize(Permission::ProjectsView->value);

        $statusCounts = fn (string $model) => $model::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total);

        $health = collect([Server::class, DatabaseInstance::class, Service::class])
            ->map($statusCounts)
            ->reduce(function (array $carry, $counts) {
                foreach ($counts as $status => $total) {
                    $carry[$status] = ($carry[$status] ?? 0) + $total;
                }

                return $carry;
            }, array_fill_keys(array_column(ResourceStatus::cases(), 'value'), 0));

        $recentActivity = $tenant->can(Permission::AuditView)
            ? AuditLogResource::collection(AuditLog::query()->with('user')->latest('created_at')->limit(10)->get())
            : [];

        return response()->json([
            'data' => [
                'projects' => [
                    'total' => Project::count(),
                    'by_status' => $statusCounts(Project::class),
                ],
                'environments' => [
                    'total' => Environment::count(),
                    'protected' => Environment::where('is_protected', true)->count(),
                ],
                'infrastructure' => [
                    'servers' => Server::count(),
                    'databases' => DatabaseInstance::count(),
                    'services' => Service::count(),
                    'health' => $health,
                ],
                'members' => $tenant->get()->members()->count(),
                'recent_activity' => $recentActivity,
            ],
        ]);
    }
}
