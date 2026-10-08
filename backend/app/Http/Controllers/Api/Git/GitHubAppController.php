<?php

namespace App\Http\Controllers\Api\Git;

use App\Enums\RiskLevel;
use App\Http\Controllers\Controller;
use App\Http\Resources\GitConnectionResource;
use App\Services\Audit\AuditLogger;
use App\Services\Git\GitConnectionService;
use App\Support\Rbac\Permission;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * GitHub App installation flow:
 *  1. POST /git-connections/github/install   → install URL carrying a single-use state
 *  2. GitHub redirects the browser to the dashboard callback with installation_id, code, state
 *  3. POST /git-connections/github/callback  → verifies state + user access, links the installation
 */
class GitHubAppController extends Controller
{
    public function __construct(
        private readonly GitConnectionService $connections,
        private readonly CurrentOrganization $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function install(Request $request): JsonResponse
    {
        Gate::authorize(Permission::IntegrationsManage->value);

        return response()->json([
            'data' => ['url' => $this->connections->startInstallation($this->tenant->get(), $request->user())],
        ]);
    }

    public function callback(Request $request): JsonResponse
    {
        Gate::authorize(Permission::IntegrationsManage->value);

        $data = $request->validate([
            'setup_action' => ['nullable', Rule::in(['install', 'update', 'request'])],
            'installation_id' => ['required_unless:setup_action,request', 'nullable', 'integer', 'min:1'],
            'state' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:100'],
        ]);

        // An organization member without admin rights on GitHub only requested the install.
        if (($data['setup_action'] ?? null) === 'request') {
            return response()->json([
                'message' => 'Installation requested. An owner of the GitHub account must approve it, then connect again.',
            ], 202);
        }

        $connection = $this->connections->completeInstallation(
            $this->tenant->get(),
            $request->user(),
            (int) $data['installation_id'],
            $data['state'],
            $data['code'] ?? null,
        );

        $this->audit->record('git_connection.installed', $connection, [
            'installation_id' => $connection->installation_id,
            'account' => $connection->account_login,
        ], risk: RiskLevel::Medium);

        return (new GitConnectionResource($connection->loadCount('repositories')))->response()->setStatusCode(201);
    }
}
