<?php

namespace App\Services\Git;

use App\Contracts\Git\GitProvider;
use App\Enums\GitConnectionStatus;
use App\Enums\RepositoryConnectionStatus;
use App\Enums\WebhookStatus;
use App\Models\GitConnection;
use App\Models\Repository;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Links registered repositories to a GitConnection, keeps their metadata and pull
 * requests in sync, and manages per-repository webhooks for token connections
 * (GitHub App connections receive the App's webhooks instead).
 */
class RepositoryLinker
{
    /** Events subscribed by repository webhooks. */
    public const WEBHOOK_EVENTS = ['push', 'pull_request', 'issues', 'check_suite', 'workflow_run', 'repository'];

    public function __construct(
        private readonly GitProviderFactory $providers,
        private readonly PullRequestRecorder $pullRequests,
    ) {}

    /**
     * @throws ValidationException|GitProviderException
     */
    public function connect(Repository $repository, GitConnection $connection, bool $createWebhook = true): Repository
    {
        if ($repository->provider !== $connection->provider) {
            throw ValidationException::withMessages(['git_connection_id' => 'The connection belongs to a different provider than the repository.']);
        }

        if ($connection->status !== GitConnectionStatus::Active) {
            throw ValidationException::withMessages(['git_connection_id' => 'The connection is not active. Verify or reconnect it first.']);
        }

        if (! $repository->full_name) {
            throw ValidationException::withMessages(['repository' => 'The repository URL does not contain an owner and name.']);
        }

        $provider = $this->providers->for($connection);

        try {
            $remote = $provider->getRepository($repository->full_name);
        } catch (GitProviderException $e) {
            if ($e->isNotFound()) {
                throw ValidationException::withMessages(['git_connection_id' => "{$repository->full_name} was not found or is not accessible with this connection."]);
            }

            throw $e;
        }

        if ($repository->git_connection_id !== null && $repository->git_connection_id !== $connection->id) {
            $this->removeWebhook($repository);
        }

        $repository->gitConnection()->associate($connection);
        $repository->fill([
            'external_id' => $remote->id,
            'full_name' => $remote->fullName,
            'default_branch' => $remote->defaultBranch,
            'is_private' => $remote->private,
            'connection_status' => RepositoryConnectionStatus::Connected,
            'connection_error' => null,
            'last_synced_at' => now(),
        ]);

        if ($connection->isApp()) {
            $repository->fill(['webhook_status' => WebhookStatus::Managed, 'webhook_error' => null]);
        } elseif ($createWebhook && $repository->webhook_status !== WebhookStatus::Active) {
            $this->installWebhook($repository, $provider);
        }

        $repository->save();

        try {
            $this->syncPullRequests($repository, $provider);
        } catch (GitProviderException) {
            // The link is established; pull requests arrive with the next sync or webhook.
        }

        return $repository;
    }

    /**
     * Refresh repository metadata and recent pull requests from the provider.
     *
     * @throws ValidationException|GitProviderException
     */
    public function sync(Repository $repository): Repository
    {
        $connection = $this->connectionOf($repository);
        $provider = $this->providers->for($connection);

        try {
            $remote = $provider->getRepository((string) $repository->full_name);
        } catch (GitProviderException $e) {
            if ($e->isNotFound() || $e->isUnauthorized()) {
                $repository->forceFill([
                    'connection_status' => RepositoryConnectionStatus::Error,
                    'connection_error' => $e->getMessage(),
                ])->save();
            }

            throw $e;
        }

        $repository->fill([
            'full_name' => $remote->fullName,
            'default_branch' => $remote->defaultBranch,
            'is_private' => $remote->private,
            'connection_status' => RepositoryConnectionStatus::Connected,
            'connection_error' => null,
            'last_synced_at' => now(),
        ])->save();

        $this->syncPullRequests($repository, $provider);

        return $repository;
    }

    public function disconnect(Repository $repository): Repository
    {
        $this->removeWebhook($repository);

        $repository->gitConnection()->dissociate();
        $repository->fill([
            'external_id' => null,
            'connection_status' => RepositoryConnectionStatus::NotConnected,
            'connection_error' => null,
            'webhook_status' => WebhookStatus::NotConfigured,
            'webhook_error' => null,
        ])->save();

        return $repository;
    }

    /**
     * The provider for a repository that must already be connected.
     *
     * @throws ValidationException|GitProviderException
     */
    public function providerFor(Repository $repository): GitProvider
    {
        return $this->providers->for($this->connectionOf($repository));
    }

    public function webhookUrl(Repository $repository): string
    {
        $base = config('services.github.webhook_base_url') ?: config('app.url');

        return rtrim((string) $base, '/').'/api/webhooks/github/repositories/'.$repository->id;
    }

    private function installWebhook(Repository $repository, GitProvider $provider): void
    {
        $secret = Str::random(48);

        try {
            $hook = $provider->createWebhook((string) $repository->full_name, $this->webhookUrl($repository), $secret, self::WEBHOOK_EVENTS);

            $repository->webhook_id = $hook->id;
            $repository->webhook_secret = $secret;
            $repository->webhook_status = WebhookStatus::Active;
            $repository->webhook_error = null;
        } catch (GitProviderException $e) {
            // The repository is still usable without webhooks (manual sync).
            $repository->webhook_status = WebhookStatus::Failed;
            $repository->webhook_error = mb_substr('Webhook not created: '.$e->getMessage(), 0, 500);
        }
    }

    /**
     * Best effort: the hook may already be gone or the token may lack permission.
     */
    private function removeWebhook(Repository $repository): void
    {
        $connection = $repository->gitConnection;

        if ($repository->webhook_id && $connection && ! $connection->isApp()) {
            try {
                $this->providers->for($connection)->deleteWebhook((string) $repository->full_name, $repository->webhook_id);
            } catch (GitProviderException) {
                // Deliveries to a removed repository are rejected (no secret), so a stale hook is harmless.
            }
        }

        $repository->webhook_id = null;
        $repository->webhook_secret = null;
        $repository->webhook_status = WebhookStatus::NotConfigured;
        $repository->webhook_error = null;
    }

    private function syncPullRequests(Repository $repository, GitProvider $provider): void
    {
        foreach ($provider->listPullRequests((string) $repository->full_name, 'all', 50) as $remote) {
            $this->pullRequests->record($repository, $remote);
        }
    }

    /**
     * @throws ValidationException
     */
    private function connectionOf(Repository $repository): GitConnection
    {
        $connection = $repository->gitConnection;

        if (! $connection) {
            throw ValidationException::withMessages(['repository' => 'Connect the repository to a git provider first.']);
        }

        if ($connection->status !== GitConnectionStatus::Active) {
            throw ValidationException::withMessages(['repository' => "The {$connection->name} connection is {$connection->status->value}. Verify or reconnect it."]);
        }

        return $connection;
    }
}
