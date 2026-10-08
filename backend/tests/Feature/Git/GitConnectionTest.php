<?php

namespace Tests\Feature\Git;

use App\Enums\WebhookStatus;
use App\Models\GitConnection;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithGitHub;
use Tests\TestCase;

class GitConnectionTest extends TestCase
{
    use InteractsWithGitHub, RefreshDatabase;

    private const TOKEN = 'github_pat_11AAAAAAA0123456789abcdefghijklmnopqrstuvwxyz';

    public function test_admin_connects_a_verified_token_that_is_never_returned(): void
    {
        [$admin, $organization] = $this->memberOf(Role::Admin);
        Http::fake([self::API.'/user' => Http::response(['login' => 'octocat', 'type' => 'User'], 200, ['X-OAuth-Scopes' => 'repo, admin:repo_hook'])]);

        $response = $this->actingInOrganization($admin, $organization)->postJson('/api/git-connections', [
            'provider' => 'github',
            'token' => self::TOKEN,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.auth_type', 'personal_access_token')
            ->assertJsonPath('data.account_login', 'octocat')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.scopes', ['repo', 'admin:repo_hook'])
            ->assertJsonMissingPath('data.credentials');

        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());

        // Encrypted at rest, decrypts for the provider.
        $stored = DB::table('git_connections')->value('credentials');
        $this->assertStringNotContainsString(self::TOKEN, $stored);
        $this->assertSame(self::TOKEN, GitConnection::first()->credentials);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));

        $audit = DB::table('audit_logs')->where('action', 'git_connection.created')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString(self::TOKEN, (string) $audit->metadata);
    }

    public function test_rejected_tokens_are_not_stored(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        Http::fake([self::API.'/user' => Http::response(['message' => 'Bad credentials'], 401)]);

        $this->actingInOrganization($owner, $organization)
            ->postJson('/api/git-connections', ['provider' => 'github', 'token' => self::TOKEN])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);

        $this->assertDatabaseCount('git_connections', 0);
    }

    public function test_only_admins_manage_connections_but_members_can_list_them(): void
    {
        [$maintainer, $organization] = $this->memberOf(Role::Maintainer);
        [$viewer] = $this->memberOf(Role::Viewer, $organization);
        $connection = $this->tokenConnection($organization);
        Http::fake();

        $this->actingInOrganization($maintainer, $organization)
            ->postJson('/api/git-connections', ['provider' => 'github', 'token' => self::TOKEN])
            ->assertForbidden();
        $this->actingInOrganization($maintainer, $organization)->deleteJson("/api/git-connections/{$connection->id}")->assertForbidden();
        $this->actingInOrganization($maintainer, $organization)->postJson('/api/git-connections/github/install')->assertForbidden();

        $this->actingInOrganization($viewer, $organization)
            ->getJson('/api/git-connections')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.credentials');

        Http::assertNothingSent();
    }

    public function test_connections_are_isolated_between_organizations(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        [, $other] = $this->memberOf(Role::Owner);
        $foreign = $this->tokenConnection($other);
        Http::fake();

        $this->actingInOrganization($owner, $organization)->getJson('/api/git-connections')->assertOk()->assertJsonCount(0, 'data');
        $this->actingInOrganization($owner, $organization)->postJson("/api/git-connections/{$foreign->id}/verify")->assertNotFound();
        $this->actingInOrganization($owner, $organization)->getJson("/api/git-connections/{$foreign->id}/remote-repositories")->assertNotFound();
        $this->actingInOrganization($owner, $organization)->deleteJson("/api/git-connections/{$foreign->id}")->assertNotFound();

        $this->assertModelExists($foreign);
        Http::assertNothingSent();
    }

    public function test_verify_marks_revoked_tokens(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $connection = $this->tokenConnection($organization);
        Http::fake([self::API.'/user' => Http::response(['message' => 'Bad credentials'], 401)]);

        $this->actingInOrganization($owner, $organization)
            ->postJson("/api/git-connections/{$connection->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.last_error', 'GitHub rejected the credentials. They may be expired or revoked.');
    }

    public function test_remote_repositories_are_listed_for_linking(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $connection = $this->tokenConnection($organization);
        Http::fake([self::API.'/user/repos*' => Http::response([$this->githubRepository('acme/api'), $this->githubRepository('acme/web', ['id' => 1002])])]);

        $this->actingInOrganization($owner, $organization)
            ->getJson("/api/git-connections/{$connection->id}/remote-repositories")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.full_name', 'acme/web');
    }

    public function test_removing_a_connection_unlinks_repositories_and_deletes_platform_webhooks(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $connection = $this->tokenConnection($organization);
        $repository = $this->linkedRepository($this->createProject($organization), $connection, [
            'full_name' => 'acme/api',
            'webhook_status' => WebhookStatus::Active,
            'webhook_id' => '555',
            'webhook_secret' => 'secret',
        ]);
        Http::fake([self::API.'/repos/acme/api/hooks/555' => Http::response(null, 204)]);

        $this->actingInOrganization($owner, $organization)
            ->deleteJson("/api/git-connections/{$connection->id}")
            ->assertOk();

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/repos/acme/api/hooks/555'));
        $this->assertModelMissing($connection);

        $repository->refresh();
        $this->assertNull($repository->git_connection_id);
        $this->assertSame('not_connected', $repository->connection_status->value);
        $this->assertNull($repository->webhook_secret);
        $this->assertDatabaseHas('audit_logs', ['action' => 'git_connection.deleted', 'organization_id' => $organization->id]);
    }

    public function test_meta_reports_whether_the_github_app_is_available(): void
    {
        [$owner] = $this->memberOf(Role::Owner);

        $this->actingAs($owner)->getJson('/api/meta')
            ->assertOk()
            ->assertJsonPath('data.integrations.github_app.enabled', false)
            ->assertJsonPath('data.git.work_branch_prefixes.0', 'fix');
    }
}
