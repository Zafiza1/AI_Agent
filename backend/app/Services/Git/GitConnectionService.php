<?php

namespace App\Services\Git;

use App\Enums\GitConnectionAuthType;
use App\Enums\GitConnectionStatus;
use App\Enums\RepositoryConnectionStatus;
use App\Enums\RepositoryProvider;
use App\Models\GitConnection;
use App\Models\Organization;
use App\Models\User;
use App\Services\Git\GitHub\GitHubAppAuth;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle of git provider connections: token connections, the GitHub App
 * installation flow, verification and removal.
 */
class GitConnectionService
{
    private const STATE_PREFIX = 'github:install-state:';

    private const STATE_TTL_MINUTES = 15;

    public function __construct(
        private readonly GitProviderFactory $providers,
        private readonly GitHubAppAuth $githubApp,
        private readonly RepositoryLinker $linker,
        private readonly Cache $cache,
    ) {}

    /**
     * Verify a personal access token against the provider, then store it encrypted.
     *
     * @throws ValidationException
     */
    public function createFromToken(RepositoryProvider $provider, string $token, ?string $name, User $actor): GitConnection
    {
        try {
            $account = $this->providers->forToken($provider, $token)->verify();
        } catch (GitProviderException $e) {
            throw ValidationException::withMessages(['token' => $e->isUnauthorized() ? 'GitHub rejected this token.' : $e->getMessage()]);
        }

        $connection = new GitConnection([
            'provider' => $provider,
            'auth_type' => GitConnectionAuthType::PersonalAccessToken,
            'name' => $name ?: "GitHub token · {$account->login}",
            'account_login' => $account->login,
            'account_type' => $account->type,
            'scopes' => $account->scopes,
            'status' => GitConnectionStatus::Active,
            'last_verified_at' => now(),
        ]);
        $connection->credentials = $token;
        $connection->created_by = $actor->id;
        $connection->save();

        return $connection;
    }

    /**
     * Begin a GitHub App installation: a single-use state bound to the organization
     * and user, checked when GitHub redirects back.
     *
     * @throws ValidationException
     */
    public function startInstallation(Organization $organization, User $user): string
    {
        if (! $this->githubApp->configured()) {
            throw ValidationException::withMessages(['github_app' => 'The GitHub App is not configured on this platform. Use a personal access token or ask the operator to configure it.']);
        }

        $state = Str::random(48);
        $this->cache->put(self::STATE_PREFIX.$state, [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ], now()->addMinutes(self::STATE_TTL_MINUTES));

        return $this->githubApp->installUrl($state);
    }

    /**
     * Link an installation after the setup redirect. The state proves the flow was
     * started here by this user; the OAuth code proves their GitHub account can
     * access the installation, so installation ids cannot be guessed or replayed.
     *
     * @throws ValidationException|GitProviderException
     */
    public function completeInstallation(Organization $organization, User $user, int $installationId, string $state, ?string $code): GitConnection
    {
        if (! $this->githubApp->configured()) {
            throw ValidationException::withMessages(['github_app' => 'The GitHub App is not configured on this platform.']);
        }

        $pending = $this->cache->pull(self::STATE_PREFIX.$state);

        if (! is_array($pending) || $pending['organization_id'] !== $organization->id || $pending['user_id'] !== $user->id) {
            throw ValidationException::withMessages(['state' => 'This installation link expired or was started by someone else. Start the installation again.']);
        }

        if (! $code) {
            throw ValidationException::withMessages(['code' => 'GitHub did not send an authorization code. Enable "Request user authorization (OAuth) during installation" in the GitHub App settings.']);
        }

        $userToken = $this->githubApp->userAccessToken($code);

        if (! $this->githubApp->userCanAccessInstallation($userToken, $installationId)) {
            throw ValidationException::withMessages(['installation_id' => 'Your GitHub account cannot access this installation.']);
        }

        $installation = $this->githubApp->installation($installationId);

        return DB::transaction(function () use ($organization, $user, $installationId, $installation) {
            $existing = GitConnection::withoutGlobalScope('organization')
                ->where('installation_id', $installationId)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->organization_id !== $organization->id) {
                throw ValidationException::withMessages(['installation_id' => 'This GitHub installation is already linked to another organization.']);
            }

            $login = (string) ($installation['account']['login'] ?? 'unknown');
            $connection = $existing ?? new GitConnection;
            $connection->fill([
                'provider' => RepositoryProvider::GitHub,
                'auth_type' => GitConnectionAuthType::GitHubApp,
                'name' => $connection->name ?? "GitHub App · {$login}",
                'account_login' => $login,
                'account_type' => strtolower((string) ($installation['account']['type'] ?? 'user')),
                'installation_id' => $installationId,
                'scopes' => $installation['permissions'] ?? [],
                'status' => empty($installation['suspended_at']) ? GitConnectionStatus::Active : GitConnectionStatus::Suspended,
                'last_error' => null,
                'last_verified_at' => now(),
            ]);
            $connection->created_by ??= $user->id;
            $connection->save();

            return $connection;
        });
    }

    /**
     * Re-check credentials and record the result on the connection.
     */
    public function verify(GitConnection $connection): GitConnection
    {
        if ($connection->isApp()) {
            $this->githubApp->forgetInstallationToken((int) $connection->installation_id);
        }

        try {
            $account = $this->providers->for($connection)->verify();

            $connection->fill([
                'account_login' => $account->login ?: $connection->account_login,
                'scopes' => $account->scopes,
                'status' => $account->suspended ? GitConnectionStatus::Suspended : GitConnectionStatus::Active,
                'last_error' => null,
                'last_verified_at' => now(),
            ]);
        } catch (GitProviderException $e) {
            $connection->fill([
                'status' => $e->isUnauthorized() || $e->isNotFound() ? GitConnectionStatus::Revoked : GitConnectionStatus::Error,
                'last_error' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }

        $connection->save();

        return $connection;
    }

    /**
     * Unlink every repository (removing platform webhooks) and delete the connection.
     * A GitHub App installation itself is left in place on GitHub.
     */
    public function remove(GitConnection $connection): void
    {
        foreach ($connection->repositories()->get() as $repository) {
            $repository->setRelation('gitConnection', $connection);
            $this->linker->disconnect($repository);
        }

        if ($connection->isApp()) {
            $this->githubApp->forgetInstallationToken((int) $connection->installation_id);
        }

        $connection->delete();
    }

    /**
     * Mark the connection's repositories as broken, e.g. after the App was uninstalled.
     */
    public function markRepositoriesBroken(GitConnection $connection, string $reason, ?array $externalIds = null): int
    {
        return $connection->repositories()
            ->when($externalIds !== null, fn ($q) => $q->whereIn('external_id', $externalIds))
            ->update([
                'connection_status' => RepositoryConnectionStatus::Error->value,
                'connection_error' => mb_substr($reason, 0, 500),
                'updated_at' => now(),
            ]);
    }
}
