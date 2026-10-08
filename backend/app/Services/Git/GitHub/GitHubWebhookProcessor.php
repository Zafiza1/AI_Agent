<?php

namespace App\Services\Git\GitHub;

use App\Enums\AuditResult;
use App\Enums\ChecksStatus;
use App\Enums\GitConnectionStatus;
use App\Enums\RepositoryConnectionStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Events\GitHubEventReceived;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\WebhookDelivery;
use App\Services\Audit\AuditLogger;
use App\Services\Git\GitConnectionService;
use App\Services\Git\PullRequestRecorder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Applies a verified GitHub delivery to platform state and emits normalized
 * GitHubEventReceived events. Runs without a tenant context, so every query here
 * is constrained explicitly by connection or repository.
 */
class GitHubWebhookProcessor
{
    public function __construct(
        private readonly PullRequestRecorder $pullRequests,
        private readonly GitConnectionService $connections,
        private readonly GitHubAppAuth $githubApp,
        private readonly AuditLogger $audit,
    ) {}

    public function process(WebhookDelivery $delivery): void
    {
        if ($delivery->status !== WebhookDeliveryStatus::Received) {
            return;
        }

        try {
            $payload = $delivery->payload ?? [];

            $handled = match ($delivery->event) {
                'installation' => $this->installation($delivery, $payload),
                'installation_repositories' => $this->installationRepositories($delivery, $payload),
                'push' => $this->push($delivery, $payload),
                'pull_request' => $this->pullRequest($delivery, $payload),
                'check_suite' => $this->checks($delivery, $payload, $payload['check_suite'] ?? [], 'check_suite'),
                'workflow_run' => $this->checks($delivery, $payload, $payload['workflow_run'] ?? [], 'workflow_run'),
                'issues' => $this->issues($delivery, $payload),
                'repository' => $this->repository($delivery, $payload),
                default => false,
            };

            $delivery->status = $handled ? WebhookDeliveryStatus::Processed : WebhookDeliveryStatus::Ignored;
            $delivery->error = null;
        } catch (Throwable $e) {
            report($e);
            $delivery->status = WebhookDeliveryStatus::Failed;
            $delivery->error = mb_substr($e->getMessage(), 0, 1000);
        }

        $delivery->processed_at = now();
        $delivery->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function installation(WebhookDelivery $delivery, array $payload): bool
    {
        $connection = $delivery->connection;
        $action = $payload['action'] ?? null;

        if (! $connection || ! in_array($action, ['deleted', 'suspend', 'unsuspend'], true)) {
            return false;
        }

        $this->githubApp->forgetInstallationToken((int) $connection->installation_id);

        $connection->status = match ($action) {
            'deleted' => GitConnectionStatus::Revoked,
            'suspend' => GitConnectionStatus::Suspended,
            'unsuspend' => GitConnectionStatus::Active,
        };
        $connection->last_error = $action === 'unsuspend' ? null : "The GitHub App installation was {$action}d on GitHub.";
        $connection->save();

        if ($action === 'deleted') {
            $this->connections->markRepositoriesBroken($connection, 'The GitHub App was uninstalled from this account.');
        }

        $this->audit->record(
            "git_connection.{$action}",
            $connection,
            ['installation_id' => $connection->installation_id, 'sender' => $payload['sender']['login'] ?? null],
            AuditResult::Success,
            organizationId: $connection->organization_id,
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function installationRepositories(WebhookDelivery $delivery, array $payload): bool
    {
        $connection = $delivery->connection;
        $removed = array_map(fn ($r) => (string) $r['id'], $payload['repositories_removed'] ?? []);

        if (! $connection || $removed === []) {
            return false;
        }

        $this->connections->markRepositoriesBroken($connection, 'Repository access was removed from the GitHub App installation.', $removed);

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function push(WebhookDelivery $delivery, array $payload): bool
    {
        $branch = str_starts_with($ref = (string) ($payload['ref'] ?? ''), 'refs/heads/') ? substr($ref, 11) : null;
        $repositories = $this->repositoriesFor($delivery, $payload);

        if ($branch === null || $repositories->isEmpty() || ! empty($payload['deleted'])) {
            return false;
        }

        foreach ($repositories as $repository) {
            if ($branch === $repository->default_branch) {
                $repository->forceFill(['last_commit_sha' => $payload['after'] ?? null, 'last_pushed_at' => now()])->save();
            }

            $this->emit('github.push', $repository, $delivery, [
                'branch' => $branch,
                'after' => $payload['after'] ?? null,
                'commits' => count($payload['commits'] ?? []),
                'pusher' => $payload['pusher']['name'] ?? null,
            ]);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pullRequest(WebhookDelivery $delivery, array $payload): bool
    {
        $repositories = $this->repositoriesFor($delivery, $payload);

        if (empty($payload['pull_request']) || $repositories->isEmpty()) {
            return false;
        }

        $remote = GitHubProvider::pullRequest($payload['pull_request']);

        foreach ($repositories as $repository) {
            $pullRequest = $this->pullRequests->record($repository, $remote);

            $this->emit('github.pull_request.'.($payload['action'] ?? 'updated'), $repository, $delivery, [
                'pull_request_id' => $pullRequest->id,
                'number' => $remote->number,
                'title' => $remote->title,
                'state' => $remote->state->value,
                'head_branch' => $remote->headBranch,
                'url' => $remote->url,
            ]);
        }

        return true;
    }

    /**
     * check_suite and workflow_run: update the CI status of matching pull requests.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $run
     */
    private function checks(WebhookDelivery $delivery, array $payload, array $run, string $source): bool
    {
        $repositories = $this->repositoriesFor($delivery, $payload);
        $headSha = $run['head_sha'] ?? null;

        if ($repositories->isEmpty() || ! $headSha) {
            return false;
        }

        $status = ($payload['action'] ?? null) === 'completed'
            ? ChecksStatus::fromConclusion($run['conclusion'] ?? null)
            : ChecksStatus::Pending;
        $numbers = array_map(fn ($pr) => (int) $pr['number'], $run['pull_requests'] ?? []);

        foreach ($repositories as $repository) {
            $pullRequests = PullRequest::query()
                ->where('repository_id', $repository->id)
                ->where(fn ($q) => $q->where('head_sha', $headSha)->when($numbers !== [], fn ($q) => $q->orWhereIn('number', $numbers)))
                ->get();

            foreach ($pullRequests as $pullRequest) {
                $pullRequest->forceFill(['checks_status' => $status, 'synced_at' => now()])->save();
            }

            if ($status === ChecksStatus::Failure) {
                $data = [
                    'source' => $source,
                    'name' => $run['name'] ?? $run['app']['name'] ?? null,
                    'head_sha' => $headSha,
                    'head_branch' => $run['head_branch'] ?? null,
                    'conclusion' => $run['conclusion'] ?? null,
                    'url' => $run['html_url'] ?? null,
                ];

                $this->emit('ci.build.failed', $repository, $delivery, $data);

                foreach ($pullRequests as $pullRequest) {
                    $this->emit('github.pull_request.failed', $repository, $delivery, [...$data, 'pull_request_id' => $pullRequest->id, 'number' => $pullRequest->number]);
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function issues(WebhookDelivery $delivery, array $payload): bool
    {
        $repositories = $this->repositoriesFor($delivery, $payload);
        $issue = $payload['issue'] ?? null;

        if (! $issue || $repositories->isEmpty() || ($payload['action'] ?? null) !== 'opened') {
            return false;
        }

        foreach ($repositories as $repository) {
            $this->emit('github.issue.created', $repository, $delivery, [
                'number' => $issue['number'] ?? null,
                'title' => mb_substr((string) ($issue['title'] ?? ''), 0, 500),
                'labels' => array_map(fn ($l) => $l['name'] ?? '', $issue['labels'] ?? []),
                'author' => $issue['user']['login'] ?? null,
                'url' => $issue['html_url'] ?? null,
            ]);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function repository(WebhookDelivery $delivery, array $payload): bool
    {
        $repositories = $this->repositoriesFor($delivery, $payload);
        $action = $payload['action'] ?? null;
        $remote = $payload['repository'] ?? [];

        if ($repositories->isEmpty()) {
            return false;
        }

        foreach ($repositories as $repository) {
            match ($action) {
                'renamed', 'transferred' => $repository->forceFill([
                    'full_name' => $remote['full_name'] ?? $repository->full_name,
                    'url' => $remote['html_url'] ?? $repository->url,
                ])->save(),
                'deleted', 'archived' => $repository->forceFill([
                    'connection_status' => RepositoryConnectionStatus::Error,
                    'connection_error' => "The repository was {$action} on GitHub.",
                ])->save(),
                'unarchived' => $repository->forceFill(['connection_status' => RepositoryConnectionStatus::Connected, 'connection_error' => null])->save(),
                'privatized', 'publicized' => $repository->forceFill(['is_private' => $action === 'privatized'])->save(),
                default => null,
            };
        }

        return in_array($action, ['renamed', 'transferred', 'deleted', 'archived', 'unarchived', 'privatized', 'publicized'], true);
    }

    /**
     * Platform repositories a delivery applies to: the one the repository webhook
     * was created for, or every repository linked through the App installation
     * with the payload's repository id (several projects may share one repository).
     *
     * @param  array<string, mixed>  $payload
     * @return Collection<int, Repository>
     */
    private function repositoriesFor(WebhookDelivery $delivery, array $payload): Collection
    {
        if ($delivery->repository_id) {
            return Repository::query()->whereKey($delivery->repository_id)->whereNotNull('git_connection_id')->get();
        }

        $externalId = $payload['repository']['id'] ?? null;

        if (! $delivery->git_connection_id || $externalId === null) {
            return new Collection;
        }

        return Repository::query()
            ->where('git_connection_id', $delivery->git_connection_id)
            ->where('external_id', (string) $externalId)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function emit(string $name, Repository $repository, WebhookDelivery $delivery, array $data): void
    {
        GitHubEventReceived::dispatch($name, $repository->organization_id, $repository->project_id, $repository->id, $delivery->delivery_id, $data);
    }
}
