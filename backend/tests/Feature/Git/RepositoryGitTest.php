<?php

namespace Tests\Feature\Git;

use App\Enums\WebhookStatus;
use App\Models\PullRequest;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithGitHub;
use Tests\TestCase;

class RepositoryGitTest extends TestCase
{
    use InteractsWithGitHub, RefreshDatabase;

    private const HEAD = 'cccccccccccccccccccccccccccccccccccccccc';

    public function test_connecting_a_repository_verifies_access_creates_a_webhook_and_syncs_pull_requests(): void
    {
        [$maintainer, $organization] = $this->memberOf(Role::Maintainer);
        $this->memberOf(Role::Owner, $organization);
        $connection = $this->tokenConnection($organization);
        $project = $this->createProject($organization, ['repository_url' => 'https://github.com/acme/api.git']);
        $repository = $project->repositories()->sole();

        Http::fake([
            self::API.'/repos/acme/api/hooks' => Http::response(['id' => 777], 201),
            self::API.'/repos/acme/api/pulls*' => Http::response([$this->githubPullRequest(7), $this->githubPullRequest(8, ['state' => 'closed', 'merged_at' => '2026-10-02T10:00:00Z'])]),
            self::API.'/repos/acme/api' => Http::response($this->githubRepository('acme/api', ['default_branch' => 'trunk'])),
        ]);

        $this->actingInOrganization($maintainer, $organization)
            ->postJson("/api/repositories/{$repository->id}/connect", ['git_connection_id' => $connection->id])
            ->assertOk()
            ->assertJsonPath('data.connection_status', 'connected')
            ->assertJsonPath('data.default_branch', 'trunk')
            ->assertJsonPath('data.is_private', true)
            ->assertJsonPath('data.webhook_status', 'active')
            ->assertJsonPath('data.git_connection.id', $connection->id)
            ->assertJsonMissingPath('data.webhook_secret');

        $repository->refresh();
        $this->assertSame('1001', $repository->external_id);
        $this->assertSame('777', $repository->webhook_id);
        $this->assertNotEmpty($repository->webhook_secret);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/hooks')
            && $r['config']['url'] === url("/api/webhooks/github/repositories/{$repository->id}")
            && $r['config']['secret'] === $repository->webhook_secret
            && in_array('pull_request', $r['events'], true));

        $this->assertSame(['open', 'merged'], PullRequest::orderBy('number')->pluck('state')->map->value->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'repository.connected', 'target_id' => $repository->id]);
    }

    public function test_webhook_failures_do_not_block_the_connection(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $connection = $this->tokenConnection($organization);
        $repository = $this->createProject($organization, ['repository_url' => 'https://github.com/acme/api'])->repositories()->sole();

        Http::fake([
            self::API.'/repos/acme/api/hooks' => Http::response(['message' => 'Validation Failed', 'errors' => [['message' => 'url is not supported because it isn\'t reachable over the public Internet (localhost)']]], 422),
            self::API.'/repos/acme/api/pulls*' => Http::response([]),
            self::API.'/repos/acme/api' => Http::response($this->githubRepository()),
        ]);

        $this->actingInOrganization($owner, $organization)
            ->postJson("/api/repositories/{$repository->id}/connect", ['git_connection_id' => $connection->id])
            ->assertOk()
            ->assertJsonPath('data.connection_status', 'connected')
            ->assertJsonPath('data.webhook_status', 'failed');

        $this->assertStringContainsString('localhost', $repository->refresh()->webhook_error);
    }

    public function test_a_repository_the_connection_cannot_see_is_rejected(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $connection = $this->tokenConnection($organization);
        $repository = $this->createProject($organization, ['repository_url' => 'https://github.com/acme/secret'])->repositories()->sole();
        Http::fake([self::API.'/repos/acme/secret' => Http::response(['message' => 'Not Found'], 404)]);

        $this->actingInOrganization($owner, $organization)
            ->postJson("/api/repositories/{$repository->id}/connect", ['git_connection_id' => $connection->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['git_connection_id']);

        $this->assertSame('not_connected', $repository->refresh()->connection_status->value);
    }

    public function test_another_organizations_connection_cannot_be_used(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        [, $other] = $this->memberOf(Role::Owner);
        $foreign = $this->tokenConnection($other);
        $repository = $this->createProject($organization)->repositories()->sole();
        Http::fake();

        $this->actingInOrganization($owner, $organization)
            ->postJson("/api/repositories/{$repository->id}/connect", ['git_connection_id' => $foreign->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['git_connection_id']);

        Http::assertNothingSent();
    }

    public function test_branches_report_protection_and_writability(): void
    {
        [$viewer, $organization] = $this->memberOf(Role::Viewer);
        $this->memberOf(Role::Owner, $organization);
        $repository = $this->linkedRepository($this->createProject($organization), $this->tokenConnection($organization), ['full_name' => 'acme/api']);

        Http::fake([self::API.'/repos/acme/api/branches*' => Http::response([
            ['name' => 'main', 'commit' => ['sha' => 'a1'], 'protected' => true],
            ['name' => 'fix/products-api', 'commit' => ['sha' => 'b2'], 'protected' => false],
            ['name' => 'wip-thing', 'commit' => ['sha' => 'c3'], 'protected' => false],
        ])]);

        $branches = $this->actingInOrganization($viewer, $organization)
            ->getJson("/api/repositories/{$repository->id}/branches")
            ->assertOk()
            ->json('data');

        $this->assertSame([true, false, false], array_column($branches, 'is_default'));
        $this->assertSame([true, false, false], array_column($branches, 'is_protected'));
        $this->assertSame([false, true, false], array_column($branches, 'is_writable'));
    }

    public function test_branches_are_created_only_with_work_prefixes(): void
    {
        [$maintainer, $organization] = $this->memberOf(Role::Maintainer);
        $this->memberOf(Role::Owner, $organization);
        $repository = $this->linkedRepository($this->createProject($organization), $this->tokenConnection($organization), ['full_name' => 'acme/api']);

        Http::fake([
            self::API.'/repos/acme/api/git/ref/heads/main' => Http::response(['object' => ['sha' => self::HEAD]]),
            self::API.'/repos/acme/api/git/refs' => Http::response(['ref' => 'refs/heads/fix/products-api'], 201),
        ]);

        foreach (['main', 'release/2.0', 'my-branch', 'chore/x'] as $name) {
            $this->actingInOrganization($maintainer, $organization)
                ->postJson("/api/repositories/{$repository->id}/branches", ['name' => $name])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name']);
        }

        $this->actingInOrganization($maintainer, $organization)
            ->postJson("/api/repositories/{$repository->id}/branches", ['name' => 'fix/products-api'])
            ->assertCreated()
            ->assertJsonPath('data.sha', self::HEAD);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/refs') && $r['ref'] === 'refs/heads/fix/products-api' && $r['sha'] === self::HEAD);
        Http::assertSentCount(2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'repository.branch_created', 'tool' => 'git.create_branch', 'risk_level' => 'medium']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'repository.write_denied', 'result' => 'denied']);
    }

    public function test_viewers_cannot_write_to_repositories(): void
    {
        [$viewer, $organization] = $this->memberOf(Role::Viewer);
        $this->memberOf(Role::Owner, $organization);
        $repository = $this->linkedRepository($this->createProject($organization), $this->tokenConnection($organization));
        Http::fake();

        $this->actingInOrganization($viewer, $organization)->postJson("/api/repositories/{$repository->id}/branches", ['name' => 'fix/x'])->assertForbidden();
        $this->actingInOrganization($viewer, $organization)->postJson("/api/repositories/{$repository->id}/commits", [])->assertForbidden();
        $this->actingInOrganization($viewer, $organization)->postJson("/api/repositories/{$repository->id}/pull-requests", [])->assertForbidden();
        $this->actingInOrganization($viewer, $organization)->postJson("/api/repositories/{$repository->id}/sync")->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_commits_are_written_through_the_git_data_api_without_force(): void
    {
        [$maintainer, $organization] = $this->memberOf(Role::Maintainer);
        $this->memberOf(Role::Owner, $organization);
        $repository = $this->linkedRepository($this->createProject($organization), $this->tokenConnection($organization), ['full_name' => 'acme/api']);

        Http::fake([
            self::API.'/repos/acme/api/branches/fix/products-api' => Http::response(['name' => 'fix/products-api', 'commit' => ['sha' => self::HEAD], 'protected' => false]),
            self::API.'/repos/acme/api/git/ref/heads/fix/products-api' => Http::response(['object' => ['sha' => self::HEAD]]),
            self::API.'/repos/acme/api/git/commits/'.self::HEAD => Http::response(['sha' => self::HEAD, 'tree' => ['sha' => 'tree-base']]),
            self::API.'/repos/acme/api/git/blobs' => Http::response(['sha' => 'blob-1'], 201),
            self::API.'/repos/acme/api/git/trees' => Http::response(['sha' => 'tree-new'], 201),
            self::API.'/repos/acme/api/git/commits' => Http::response(['sha' => 'commit-new', 'message' => 'Fix products API', 'html_url' => 'https://github.com/acme/api/commit/commit-new', 'author' => ['name' => 'Platform', 'date' => '2026-10-08T00:00:00Z']], 201),
            self::API.'/repos/acme/api/git/refs/heads/fix/products-api' => Http::response(['object' => ['sha' => 'commit-new']]),
        ]);

        $this->actingInOrganization($maintainer, $organization)
            ->postJson("/api/repositories/{$repository->id}/commits", [
                'branch' => 'fix/products-api',
                'message' => 'Fix products API',
                'files' => [
                    ['path' => 'app/Http/Controllers/ProductController.php', 'content' => '<?php // fixed'],
                    ['path' => 'legacy/old.php', 'delete' => true],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.sha', 'commit-new');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/blobs') && base64_decode($r['content']) === '<?php // fixed');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/trees')
            && $r['base_tree'] === 'tree-base'
            && $r['tree'][0]['sha'] === 'blob-1'
            && $r['tree'][1] === ['path' => 'legacy/old.php', 'mode' => '100644', 'type' => 'blob', 'sha' => null]);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/git/commits') && $r['parents'] === [self::HEAD]);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['sha'] === 'commit-new' && $r['force'] === false);

        $this->assertDatabaseHas('audit_logs', ['action' => 'repository.committed', 'tool' => 'git.commit']);
    }

    public function test_commits_to_protected_branches_or_unsafe_paths_are_refused(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $repository = $this->linkedRepository($this->createProject($organization), $this->tokenConnection($organization), ['full_name' => 'acme/api']);
        Http::fake([self::API.'/repos/acme/api/branches/fix/locked' => Http::response(['name' => 'fix/locked', 'commit' => ['sha' => self::HEAD], 'protected' => true])]);

        $file = [['path' => 'README.md', 'content' => 'x']];

        $this->actingInOrganization($owner, $organization)
            ->postJson("/api/repositories/{$repository->id}/commits", ['branch' => 'main', 'message' => 'm', 'files' => $file])
            ->assertUnprocessable()->assertJsonValidationErrors(['branch']);

        foreach (['../etc/passwd', '/abs/path', 'a/../../b', '.git/config', 'dir/./file'] as $path) {
            $this->actingInOrganization($owner, $organization)
                ->postJson("/api/repositories/{$repository->id}/commits", ['branch' => 'fix/x', 'message' => 'm', 'files' => [['path' => $path, 'content' => 'x']]])
                ->assertUnprocessable()->assertJsonValidationErrors(['files.0.path']);
        }

        // Protected on GitHub even though the name is a work branch.
        $this->actingInOrganization($owner, $organization)
            ->postJson("/api/repositories/{$repository->id}/commits", ['branch' => 'fix/locked', 'message' => 'm', 'files' => $file])
            ->assertUnprocessable()->assertJsonValidationErrors(['branch']);

        Http::assertSentCount(1);
    }

    public function test_pull_requests_are_opened_from_work_branches_and_recorded(): void
    {
        [$maintainer, $organization] = $this->memberOf(Role::Maintainer);
        $this->memberOf(Role::Owner, $organization);
        $repository = $this->linkedRepository($this->createProject($organization), $this->tokenConnection($organization), ['full_name' => 'acme/api']);
        Http::fake([self::API.'/repos/acme/api/pulls' => Http::response($this->githubPullRequest(12), 201)]);

        $this->actingInOrganization($maintainer, $organization)
            ->postJson("/api/repositories/{$repository->id}/pull-requests", ['head' => 'main', 'title' => 'Nope'])
            ->assertUnprocessable()->assertJsonValidationErrors(['head']);

        $this->actingInOrganization($maintainer, $organization)
            ->postJson("/api/repositories/{$repository->id}/pull-requests", [
                'head' => 'fix/products-api',
                'title' => 'Fix products API 500',
                'body' => 'Root cause: removed column.',
                'draft' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.number', 12)
            ->assertJsonPath('data.state', 'open')
            ->assertJsonPath('data.opened_via_platform', true)
            ->assertJsonPath('data.opened_by.id', $maintainer->id);

        Http::assertSent(fn (Request $r) => $r['base'] === 'main' && $r['head'] === 'fix/products-api' && $r['draft'] === true);

        $this->actingInOrganization($maintainer, $organization)
            ->getJson('/api/pull-requests?state=open')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.repository.full_name', 'acme/api');

        $this->assertDatabaseHas('audit_logs', ['action' => 'pull_request.created', 'tool' => 'git.create_pull_request']);
    }

    public function test_pull_requests_are_isolated_between_organizations(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        [$outsider, $other] = $this->memberOf(Role::Owner);
        $repository = $this->linkedRepository($this->createProject($organization), $this->tokenConnection($organization), ['full_name' => 'acme/api']);
        Http::fake([self::API.'/repos/acme/api/pulls' => Http::response($this->githubPullRequest(3), 201)]);

        $id = $this->actingInOrganization($owner, $organization)
            ->postJson("/api/repositories/{$repository->id}/pull-requests", ['head' => 'fix/a', 'title' => 'A'])
            ->json('data.id');

        $this->actingInOrganization($outsider, $other)->getJson('/api/pull-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->actingInOrganization($outsider, $other)->getJson("/api/pull-requests/{$id}")->assertNotFound();
        $this->actingInOrganization($outsider, $other)->getJson("/api/repositories/{$repository->id}/branches")->assertNotFound();
        $this->actingInOrganization($outsider, $other)->getJson('/api/repositories')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_provider_errors_are_reported_without_leaking_credentials(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $connection = $this->tokenConnection($organization);
        $repository = $this->linkedRepository($this->createProject($organization), $connection, ['full_name' => 'acme/api']);
        Http::fake([self::API.'/repos/acme/api' => Http::response(['message' => 'Bad credentials'], 401)]);

        $response = $this->actingInOrganization($owner, $organization)
            ->postJson("/api/repositories/{$repository->id}/sync")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'GitHub rejected the credentials. They may be expired or revoked.');

        $this->assertStringNotContainsString($connection->credentials, $response->getContent());
        $this->assertSame('error', $repository->refresh()->connection_status->value);
    }

    public function test_operations_need_a_connected_repository(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $repository = $this->createProject($organization)->repositories()->sole();

        $this->actingInOrganization($owner, $organization)
            ->getJson("/api/repositories/{$repository->id}/branches")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['repository']);
    }

    public function test_disconnecting_removes_the_platform_webhook(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $repository = $this->linkedRepository($this->createProject($organization), $this->tokenConnection($organization), [
            'full_name' => 'acme/api',
            'webhook_status' => WebhookStatus::Active,
            'webhook_id' => '777',
            'webhook_secret' => 'secret',
        ]);
        Http::fake([self::API.'/repos/acme/api/hooks/777' => Http::response(null, 204)]);

        $this->actingInOrganization($owner, $organization)
            ->postJson("/api/repositories/{$repository->id}/disconnect")
            ->assertOk()
            ->assertJsonPath('data.connection_status', 'not_connected')
            ->assertJsonPath('data.webhook_status', 'not_configured');

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/hooks/777'));
        $this->assertNull($repository->refresh()->webhook_secret);
    }
}
