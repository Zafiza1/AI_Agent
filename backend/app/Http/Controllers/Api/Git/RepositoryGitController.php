<?php

namespace App\Http\Controllers\Api\Git;

use App\Enums\AuditResult;
use App\Enums\RiskLevel;
use App\Http\Controllers\Controller;
use App\Http\Resources\PullRequestResource;
use App\Http\Resources\RepositoryResource;
use App\Http\Resources\WebhookDeliveryResource;
use App\Models\GitConnection;
use App\Models\Repository;
use App\Models\WebhookDelivery;
use App\Services\Audit\AuditLogger;
use App\Services\Git\Data\FileChange;
use App\Services\Git\PullRequestRecorder;
use App\Services\Git\RepositoryLinker;
use App\Support\Git\BranchPolicy;
use App\Support\Rbac\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Provider operations on a registered repository. Writes are restricted by
 * BranchPolicy (never the default or long-lived branches) and are audited as
 * MEDIUM risk tool calls, the same way agents will invoke them from Phase 3.
 */
class RepositoryGitController extends Controller
{
    private const BRANCH_RULE = ['string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'];

    private const MAX_COMMIT_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly RepositoryLinker $linker,
        private readonly PullRequestRecorder $pullRequests,
        private readonly AuditLogger $audit,
    ) {}

    public function connect(Request $request, Repository $repository): RepositoryResource
    {
        Gate::authorize(Permission::ProjectsUpdate->value);

        $data = $request->validate([
            'git_connection_id' => ['required', 'uuid'],
            'create_webhook' => ['sometimes', 'boolean'],
        ]);

        // Scoped to the current organization: another tenant's connection is "not found".
        $connection = GitConnection::query()->find($data['git_connection_id'])
            ?? throw ValidationException::withMessages(['git_connection_id' => 'The selected connection does not exist.']);

        $this->linker->connect($repository, $connection, $data['create_webhook'] ?? true);

        $this->audit->record('repository.connected', $repository, [
            'git_connection_id' => $connection->id,
            'full_name' => $repository->full_name,
            'webhook_status' => $repository->webhook_status->value,
        ], risk: RiskLevel::Low);

        return new RepositoryResource($repository->load('gitConnection'));
    }

    public function disconnect(Repository $repository): RepositoryResource
    {
        Gate::authorize(Permission::ProjectsUpdate->value);

        $this->linker->disconnect($repository);
        $this->audit->record('repository.disconnected', $repository, ['full_name' => $repository->full_name]);

        return new RepositoryResource($repository->load('gitConnection'));
    }

    public function sync(Repository $repository): RepositoryResource
    {
        Gate::authorize(Permission::ProjectsUpdate->value);

        $this->linker->sync($repository);
        $this->audit->record('repository.synced', $repository, ['full_name' => $repository->full_name]);

        return new RepositoryResource($repository->load('gitConnection')->loadCount('openPullRequests'));
    }

    public function branches(Repository $repository): JsonResponse
    {
        Gate::authorize(Permission::ProjectsView->value);

        $branches = $this->linker->providerFor($repository)->listBranches((string) $repository->full_name);

        return response()->json(['data' => array_map(fn ($branch) => [
            'name' => $branch->name,
            'sha' => $branch->sha,
            'is_default' => $branch->name === $repository->default_branch,
            'is_protected' => $branch->protected || BranchPolicy::isProtected($repository, $branch->name),
            'is_writable' => ! $branch->protected && BranchPolicy::writeViolation($repository, $branch->name) === null,
        ], $branches)]);
    }

    public function createBranch(Request $request, Repository $repository): JsonResponse
    {
        Gate::authorize(Permission::RepositoriesWrite->value);

        $data = $request->validate([
            'name' => ['required', ...self::BRANCH_RULE],
            'from' => ['nullable', ...self::BRANCH_RULE],
        ]);
        $this->assertWritable($repository, $data['name'], 'name');

        $from = $data['from'] ?? $repository->default_branch;
        $branch = $this->linker->providerFor($repository)->createBranch((string) $repository->full_name, $data['name'], $from);

        $this->audit->record('repository.branch_created', $repository, [
            'branch' => $branch->name,
            'from' => $from,
            'sha' => $branch->sha,
        ], risk: RiskLevel::Medium, tool: 'git.create_branch');

        return response()->json(['data' => ['name' => $branch->name, 'sha' => $branch->sha]], 201);
    }

    public function commits(Request $request, Repository $repository): JsonResponse
    {
        Gate::authorize(Permission::ProjectsView->value);

        $data = $request->validate([
            'branch' => ['nullable', ...self::BRANCH_RULE],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $commits = $this->linker->providerFor($repository)->listCommits(
            (string) $repository->full_name,
            $data['branch'] ?? $repository->default_branch,
            (int) ($data['limit'] ?? 30),
        );

        return response()->json(['data' => array_map(fn ($c) => $c->toArray(), $commits)]);
    }

    /**
     * Commit file changes to a work branch. This is the write path agents use
     * (through the Tool Gateway) after changes were tested in a sandbox.
     */
    public function commit(Request $request, Repository $repository): JsonResponse
    {
        Gate::authorize(Permission::RepositoriesWrite->value);

        $data = $request->validate([
            'branch' => ['required', ...self::BRANCH_RULE],
            'message' => ['required', 'string', 'max:5000'],
            'files' => ['required', 'array', 'min:1', 'max:100'],
            'files.*.path' => ['required', 'string', 'max:400', 'distinct', 'regex:/^(?!\/)(?!.*(^|\/)\.\.?(\/|$))(?!\.git(\/|$))[^\x00-\x1f\\\\]+$/'],
            'files.*.content' => ['nullable', 'string', 'required_unless:files.*.delete,true'],
            'files.*.delete' => ['sometimes', 'boolean'],
        ]);
        $this->assertWritable($repository, $data['branch'], 'branch');

        $changes = array_map(
            fn (array $file) => new FileChange($file['path'], ! empty($file['delete']) ? null : (string) ($file['content'] ?? '')),
            $data['files'],
        );

        if (array_sum(array_map(fn (FileChange $c) => strlen((string) $c->content), $changes)) > self::MAX_COMMIT_BYTES) {
            throw ValidationException::withMessages(['files' => 'A commit may contain at most 5 MB of file content.']);
        }

        $provider = $this->linker->providerFor($repository);

        if ($provider->getBranch((string) $repository->full_name, $data['branch'])->protected) {
            throw ValidationException::withMessages(['branch' => 'This branch is protected on the provider.']);
        }

        $commit = $provider->commitFiles((string) $repository->full_name, $data['branch'], $data['message'], $changes);

        $this->audit->record('repository.committed', $repository, [
            'branch' => $data['branch'],
            'sha' => $commit->sha,
            'files' => array_map(fn (FileChange $c) => ['path' => $c->path, 'deleted' => $c->isDeletion()], $changes),
        ], risk: RiskLevel::Medium, tool: 'git.commit');

        return response()->json(['data' => $commit->toArray()], 201);
    }

    public function pullRequests(Request $request, Repository $repository): AnonymousResourceCollection
    {
        Gate::authorize(Permission::ProjectsView->value);

        $state = $request->validate(['state' => ['nullable', Rule::in(['open', 'closed', 'merged'])]])['state'] ?? null;

        return PullRequestResource::collection(
            $repository->pullRequests()->with('opener')
                ->when($state, fn ($q) => $q->where('state', $state))
                ->orderByDesc('number')
                ->paginate(30)
        );
    }

    public function createPullRequest(Request $request, Repository $repository): JsonResponse
    {
        Gate::authorize(Permission::RepositoriesWrite->value);

        $data = $request->validate([
            'head' => ['required', ...self::BRANCH_RULE],
            'base' => ['nullable', ...self::BRANCH_RULE, 'different:head'],
            'title' => ['required', 'string', 'max:256'],
            'body' => ['nullable', 'string', 'max:60000'],
            'draft' => ['sometimes', 'boolean'],
        ]);
        $this->assertWritable($repository, $data['head'], 'head');

        $base = $data['base'] ?? $repository->default_branch;
        $remote = $this->linker->providerFor($repository)->createPullRequest(
            (string) $repository->full_name,
            $data['head'],
            $base,
            $data['title'],
            $data['body'] ?? '',
            (bool) ($data['draft'] ?? false),
        );

        $pullRequest = $this->pullRequests->record($repository, $remote, $request->user());

        $this->audit->record('pull_request.created', $pullRequest, [
            'repository' => $repository->full_name,
            'number' => $pullRequest->number,
            'head' => $pullRequest->head_branch,
            'base' => $pullRequest->base_branch,
        ], risk: RiskLevel::Medium, tool: 'git.create_pull_request');

        return (new PullRequestResource($pullRequest->load('opener')))->response()->setStatusCode(201);
    }

    public function deliveries(Repository $repository): AnonymousResourceCollection
    {
        Gate::authorize(Permission::ProjectsView->value);

        $deliveries = WebhookDelivery::query()
            ->where(fn ($q) => $q->where('repository_id', $repository->id)
                ->when($repository->git_connection_id, fn ($q) => $q->orWhere(fn ($q) => $q
                    ->where('git_connection_id', $repository->git_connection_id)
                    ->whereNull('repository_id')
                    ->where('external_repository_id', $repository->external_id))))
            ->latest('received_at')
            ->limit(50)
            ->get();

        return WebhookDeliveryResource::collection($deliveries);
    }

    /**
     * @throws ValidationException
     */
    private function assertWritable(Repository $repository, string $branch, string $field): void
    {
        if ($violation = BranchPolicy::writeViolation($repository, $branch)) {
            $this->audit->record('repository.write_denied', $repository, [
                'branch' => $branch,
                'reason' => $violation,
            ], AuditResult::Denied, RiskLevel::High);

            throw ValidationException::withMessages([$field => $violation]);
        }
    }
}
