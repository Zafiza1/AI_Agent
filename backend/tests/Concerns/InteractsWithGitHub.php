<?php

namespace Tests\Concerns;

use App\Enums\GitConnectionAuthType;
use App\Enums\GitConnectionStatus;
use App\Enums\RepositoryConnectionStatus;
use App\Enums\RepositoryProvider;
use App\Enums\WebhookStatus;
use App\Models\GitConnection;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Testing\TestResponse;

/**
 * Fixtures for GitHub integration tests. Every GitHub call is faked with Http::fake();
 * tests never reach the network.
 */
trait InteractsWithGitHub
{
    protected const API = 'https://api.github.com';

    protected function tokenConnection(Organization $organization, array $attributes = []): GitConnection
    {
        $connection = new GitConnection([
            'provider' => RepositoryProvider::GitHub,
            'auth_type' => GitConnectionAuthType::PersonalAccessToken,
            'name' => 'GitHub token · octocat',
            'account_login' => 'octocat',
            'account_type' => 'user',
            'status' => GitConnectionStatus::Active,
            ...$attributes,
        ]);
        $connection->organization_id = $organization->id;
        $connection->credentials = 'ghp_'.str_repeat('a', 36);
        $connection->save();

        return $connection;
    }

    protected function appConnection(Organization $organization, int $installationId = 4242): GitConnection
    {
        $connection = new GitConnection([
            'provider' => RepositoryProvider::GitHub,
            'auth_type' => GitConnectionAuthType::GitHubApp,
            'name' => 'GitHub App · acme',
            'account_login' => 'acme',
            'account_type' => 'organization',
            'installation_id' => $installationId,
            'status' => GitConnectionStatus::Active,
        ]);
        $connection->organization_id = $organization->id;
        $connection->save();

        return $connection;
    }

    /**
     * The project's primary repository, linked to $connection as if connect() ran.
     */
    protected function linkedRepository(Project $project, GitConnection $connection, array $attributes = []): Repository
    {
        $repository = $project->repositories()->where('is_primary', true)->firstOrFail();
        $repository->gitConnection()->associate($connection);
        $repository->forceFill([
            'external_id' => '1001',
            'connection_status' => RepositoryConnectionStatus::Connected,
            'webhook_status' => $connection->isApp() ? WebhookStatus::Managed : WebhookStatus::NotConfigured,
            ...$attributes,
        ])->save();

        return $repository;
    }

    /**
     * Configure the GitHub App with a freshly generated RSA key.
     */
    protected function configureGitHubApp(): void
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);

        // Windows PHP builds need an explicit openssl.cnf.
        if ($key === false && is_file($cnf = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf')) {
            $options['config'] = $cnf;
            $key = openssl_pkey_new($options);
        }

        if ($key === false) {
            $this->markTestSkipped('OpenSSL cannot generate RSA keys in this environment.');
        }

        openssl_pkey_export($key, $pem, null, $options);

        config([
            'services.github.app.id' => '123456',
            'services.github.app.slug' => 'maintenance-platform',
            'services.github.app.client_id' => 'Iv1.testclient',
            'services.github.app.client_secret' => 'test-client-secret',
            'services.github.app.private_key' => $pem,
            'services.github.app.webhook_secret' => 'app-webhook-secret',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function githubRepository(string $fullName = 'acme/api', array $overrides = []): array
    {
        return [
            'id' => 1001,
            'full_name' => $fullName,
            'html_url' => "https://github.com/{$fullName}",
            'default_branch' => 'main',
            'private' => true,
            'archived' => false,
            'permissions' => ['admin' => true, 'push' => true],
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function githubPullRequest(int $number = 7, array $overrides = []): array
    {
        return [
            'id' => 900000 + $number,
            'number' => $number,
            'title' => 'Fix products API 500',
            'state' => 'open',
            'draft' => false,
            'merged_at' => null,
            'closed_at' => null,
            'created_at' => '2026-10-01T10:00:00Z',
            'html_url' => "https://github.com/acme/api/pull/{$number}",
            'user' => ['login' => 'octocat'],
            'head' => ['ref' => 'fix/products-api', 'sha' => str_repeat('a', 40)],
            'base' => ['ref' => 'main', 'sha' => str_repeat('b', 40)],
            ...$overrides,
        ];
    }

    /**
     * POST a webhook delivery signed with $secret.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function deliverWebhook(string $uri, string $event, array $payload, ?string $secret, ?string $deliveryId = null): TestResponse
    {
        $body = json_encode($payload);
        $headers = [
            'X-GitHub-Event' => $event,
            'X-GitHub-Delivery' => $deliveryId ?? fake()->uuid(),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        if ($secret !== null) {
            $headers['X-Hub-Signature-256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        $server = [];
        foreach ($headers as $name => $value) {
            $server[str_starts_with($name, 'X-') ? 'HTTP_'.strtoupper(str_replace('-', '_', $name)) : $name] = $value;
        }

        return $this->call('POST', $uri, [], [], [], $server, $body);
    }
}
