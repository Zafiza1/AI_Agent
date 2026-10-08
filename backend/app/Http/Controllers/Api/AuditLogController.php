<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActorType;
use App\Enums\AuditResult;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\Rbac\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize(Permission::AuditView->value);

        $filters = $request->validate([
            'project_id' => ['nullable', 'uuid'],
            'action' => ['nullable', 'string', 'max:100'],
            'actor_type' => ['nullable', Rule::enum(ActorType::class)],
            'result' => ['nullable', Rule::enum(AuditResult::class)],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // The tenant global scope on AuditLog limits results to the current organization.
        $logs = AuditLog::query()
            ->with('user')
            ->when($filters['project_id'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            // Prefix match without LIKE, so "_" and "%" in the filter are literal on every database.
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->whereRaw('substr(action, 1, ?) = ?', [mb_strlen($v), $v]))
            ->when($filters['actor_type'] ?? null, fn ($q, $v) => $q->where('actor_type', $v))
            ->when($filters['result'] ?? null, fn ($q, $v) => $q->where('result', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50);

        return AuditLogResource::collection($logs);
    }
}
