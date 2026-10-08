<?php

namespace App\Http\Controllers\Api;

use App\Contracts\SecretStore;
use App\Enums\RiskLevel;
use App\Http\Controllers\Controller;
use App\Http\Resources\EnvironmentVariableResource;
use App\Models\Environment;
use App\Services\Audit\AuditLogger;
use App\Support\Rbac\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Environment variables and secrets. Values are write-only for secrets: the API
 * returns keys and metadata, never secret plaintext, and audit entries record
 * only the keys that changed.
 */
class EnvironmentVariableController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecretStore $secrets,
    ) {}

    public function index(Environment $environment): AnonymousResourceCollection
    {
        Gate::authorize(Permission::ProjectsView->value);

        return EnvironmentVariableResource::collection($environment->variables()->orderBy('key')->get());
    }

    /**
     * Create or replace variables in bulk.
     */
    public function upsert(Request $request, Environment $environment): AnonymousResourceCollection
    {
        $this->authorizeWrite($environment);

        $data = $request->validate([
            'variables' => ['required', 'array', 'min:1', 'max:100'],
            'variables.*.key' => ['required', 'string', 'max:128', 'regex:/^[A-Z_][A-Z0-9_]*$/', 'distinct'],
            'variables.*.value' => ['present', 'nullable', 'string', 'max:16384'],
            'variables.*.is_secret' => ['sometimes', 'boolean'],
        ], [
            'variables.*.key.regex' => 'Variable keys must be UPPER_SNAKE_CASE.',
        ]);

        $user = $request->user();

        $variables = DB::transaction(fn () => collect($data['variables'])->map(
            fn (array $item) => $this->secrets->put(
                $environment,
                $item['key'],
                (string) ($item['value'] ?? ''),
                (bool) ($item['is_secret'] ?? true),
                $user,
            ),
        ));

        $this->audit->record('environment.variables_updated', $environment, [
            'keys' => $variables->pluck('key')->all(),
        ], risk: $environment->is_protected ? RiskLevel::High : RiskLevel::Medium);

        return EnvironmentVariableResource::collection($environment->variables()->orderBy('key')->get());
    }

    public function destroy(Environment $environment, string $variable): JsonResponse
    {
        $this->authorizeWrite($environment);

        $variable = $environment->variables()->whereKey($variable)->firstOrFail();
        $this->secrets->forget($variable);

        $this->audit->record('environment.variable_deleted', $environment, ['key' => $variable->key],
            risk: $environment->is_protected ? RiskLevel::High : RiskLevel::Medium);

        return response()->json(['message' => 'Variable deleted.']);
    }

    private function authorizeWrite(Environment $environment): void
    {
        Gate::authorize(Permission::SecretsManage->value);
        Gate::authorize($environment->managePermission()->value);
    }
}
