<?php

namespace App\Services\Git;

use App\Contracts\Git\GitProvider;
use App\Enums\RepositoryProvider;
use App\Models\GitConnection;
use App\Services\Git\GitHub\GitHubAppAuth;
use App\Services\Git\GitHub\GitHubClient;
use App\Services\Git\GitHub\GitHubProvider;

/**
 * Resolves the GitProvider implementation and credentials for a connection.
 * Credentials are decrypted only here and handed to the HTTP client, never returned.
 */
class GitProviderFactory
{
    public function __construct(private readonly GitHubAppAuth $githubApp) {}

    /**
     * @throws GitProviderException
     */
    public function for(GitConnection $connection): GitProvider
    {
        $this->assertSupported($connection->provider);

        if ($connection->isApp()) {
            if (! $this->githubApp->configured()) {
                throw new GitProviderException('The GitHub App is not configured on this platform.', 503);
            }

            $installationId = (int) $connection->installation_id;

            return new GitHubProvider(new GitHubClient($this->githubApp->installationToken($installationId)), $this->githubApp, $installationId);
        }

        return $this->forToken($connection->provider, (string) $connection->credentials);
    }

    /**
     * A provider for a token that is not stored yet (used to verify it first).
     *
     * @throws GitProviderException
     */
    public function forToken(RepositoryProvider $provider, string $token): GitProvider
    {
        $this->assertSupported($provider);

        return new GitHubProvider(new GitHubClient($token), $this->githubApp);
    }

    private function assertSupported(RepositoryProvider $provider): void
    {
        if ($provider !== RepositoryProvider::GitHub) {
            throw new GitProviderException("The {$provider->value} provider is not supported yet.", 422);
        }
    }
}
