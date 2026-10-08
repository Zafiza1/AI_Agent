<?php

namespace Tests\Feature\Git;

use App\Enums\WebhookStatus;
use App\Events\GitHubEventReceived;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\WebhookDelivery;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithGitHub;
use Tests\TestCase;

class GitHubWebhookTest extends TestCase
{
    use InteractsWithGitHub, RefreshDatabase;

    private const SECRET = 'repository-webhook-secret';

    private function repositoryWithWebhook(): Repository
    {
        [, $organization] = $this->memberOf(Role::Owner);

        return $this->linkedRepository($this->createProject($organization), $this->tokenConnection($organization), [
            'full_name' => 'acme/api',
            'webhook_status' => WebhookStatus::Active,
            'webhook_id' => '777',
            'webhook_secret' => self::SECRET,
        ]);
    }

    private function uri(Repository $repository): string
    {
        return "/api/webhooks/github/repositories/{$repository->id}";
    }

    public function test_deliveries_with_a_bad_or_missing_signature_are_rejected(): void
    {
        $repository = $this->repositoryWithWebhook();
        $payload = ['action' => 'opened', 'pull_request' => $this->githubPullRequest()];

        $this->deliverWebhook($this->uri($repository), 'pull_request', $payload, 'wrong-secret')->assertUnauthorized();
        $this->deliverWebhook($this->uri($repository), 'pull_request', $payload, null)->assertUnauthorized();
        $this->deliverWebhook('/api/webhooks/github/repositories/'.fake()->uuid(), 'pull_request', $payload, self::SECRET)->assertNotFound();

        $this->assertDatabaseCount('webhook_deliveries', 0);
        $this->assertDatabaseCount('pull_requests', 0);
    }

    public function test_pull_request_events_update_the_mirror_and_emit_normalized_events(): void
    {
        Event::fake([GitHubEventReceived::class]);
        $repository = $this->repositoryWithWebhook();

        $this->deliverWebhook($this->uri($repository), 'pull_request', ['action' => 'opened', 'pull_request' => $this->githubPullRequest(5)], self::SECRET, 'delivery-1')
            ->assertStatus(202);

        $pullRequest = PullRequest::withoutGlobalScopes()->sole();
        $this->assertSame(5, $pullRequest->number);
        $this->assertSame($repository->organization_id, $pullRequest->organization_id);
        $this->assertSame('processed', WebhookDelivery::sole()->status->value);

        $this->deliverWebhook($this->uri($repository), 'pull_request', [
            'action' => 'closed',
            'pull_request' => $this->githubPullRequest(5, ['state' => 'closed', 'merged' => true, 'merged_at' => '2026-10-03T00:00:00Z']),
        ], self::SECRET, 'delivery-2')->assertStatus(202);

        $this->assertSame('merged', $pullRequest->refresh()->state->value);

        Event::assertDispatched(GitHubEventReceived::class, fn ($e) => $e->name === 'github.pull_request.opened'
            && $e->repositoryId === $repository->id
            && $e->organizationId === $repository->organization_id
            && $e->data['number'] === 5);
        Event::assertDispatched(GitHubEventReceived::class, fn ($e) => $e->name === 'github.pull_request.closed');
    }

    public function test_deliveries_are_idempotent(): void
    {
        $repository = $this->repositoryWithWebhook();
        $payload = ['action' => 'opened', 'pull_request' => $this->githubPullRequest()];

        $this->deliverWebhook($this->uri($repository), 'pull_request', $payload, self::SECRET, 'same-delivery')->assertStatus(202);
        $this->deliverWebhook($this->uri($repository), 'pull_request', $payload, self::SECRET, 'same-delivery')->assertOk();

        $this->assertDatabaseCount('webhook_deliveries', 1);
        $this->assertDatabaseCount('pull_requests', 1);
    }

    public function test_ping_is_answered_without_storing(): void
    {
        $repository = $this->repositoryWithWebhook();

        $this->deliverWebhook($this->uri($repository), 'ping', ['zen' => 'Keep it logically awesome.'], self::SECRET)
            ->assertOk()
            ->assertJsonPath('message', 'pong');

        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_pushes_to_the_default_branch_record_the_head_commit(): void
    {
        Event::fake([GitHubEventReceived::class]);
        $repository = $this->repositoryWithWebhook();
        $sha = str_repeat('d', 40);

        $this->deliverWebhook($this->uri($repository), 'push', ['ref' => 'refs/heads/main', 'after' => $sha, 'commits' => [[], []], 'pusher' => ['name' => 'octocat']], self::SECRET)
            ->assertStatus(202);

        $repository->refresh();
        $this->assertSame($sha, $repository->last_commit_sha);
        $this->assertNotNull($repository->last_pushed_at);
        Event::assertDispatched(GitHubEventReceived::class, fn ($e) => $e->name === 'github.push' && $e->data['commits'] === 2);
    }

    public function test_failed_ci_marks_pull_requests_and_emits_build_failures(): void
    {
        Event::fake([GitHubEventReceived::class]);
        $repository = $this->repositoryWithWebhook();
        $head = str_repeat('a', 40);

        $this->deliverWebhook($this->uri($repository), 'pull_request', ['action' => 'opened', 'pull_request' => $this->githubPullRequest(9)], self::SECRET);

        $this->deliverWebhook($this->uri($repository), 'workflow_run', [
            'action' => 'completed',
            'workflow_run' => ['name' => 'CI', 'head_sha' => $head, 'head_branch' => 'fix/products-api', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/api/actions/runs/1', 'pull_requests' => [['number' => 9]]],
        ], self::SECRET)->assertStatus(202);

        $this->assertSame('failure', PullRequest::withoutGlobalScopes()->sole()->checks_status->value);
        Event::assertDispatched(GitHubEventReceived::class, fn ($e) => $e->name === 'ci.build.failed' && $e->data['name'] === 'CI');
        Event::assertDispatched(GitHubEventReceived::class, fn ($e) => $e->name === 'github.pull_request.failed' && $e->data['number'] === 9);

        $this->deliverWebhook($this->uri($repository), 'check_suite', [
            'action' => 'completed',
            'check_suite' => ['head_sha' => $head, 'conclusion' => 'success', 'pull_requests' => []],
        ], self::SECRET);

        $this->assertSame('success', PullRequest::withoutGlobalScopes()->sole()->checks_status->value);
    }

    public function test_opened_issues_emit_issue_created(): void
    {
        Event::fake([GitHubEventReceived::class]);
        $repository = $this->repositoryWithWebhook();

        $this->deliverWebhook($this->uri($repository), 'issues', [
            'action' => 'opened',
            'issue' => ['number' => 31, 'title' => 'API /api/products returns 500', 'labels' => [['name' => 'bug']], 'user' => ['login' => 'customer'], 'html_url' => 'https://github.com/acme/api/issues/31'],
        ], self::SECRET)->assertStatus(202);

        Event::assertDispatched(GitHubEventReceived::class, fn ($e) => $e->name === 'github.issue.created'
            && $e->data['labels'] === ['bug']
            && $e->projectId === $repository->project_id);
    }

    public function test_renamed_repositories_follow_the_new_name(): void
    {
        $repository = $this->repositoryWithWebhook();

        $this->deliverWebhook($this->uri($repository), 'repository', [
            'action' => 'renamed',
            'repository' => ['id' => 1001, 'full_name' => 'acme/api-v2', 'html_url' => 'https://github.com/acme/api-v2'],
        ], self::SECRET)->assertStatus(202);

        $this->assertSame('acme/api-v2', $repository->refresh()->full_name);
    }

    public function test_app_webhooks_need_the_app_secret(): void
    {
        $this->deliverWebhook('/api/webhooks/github', 'installation', ['action' => 'deleted'], 'anything')->assertNotFound();

        $this->configureGitHubApp();

        $this->deliverWebhook('/api/webhooks/github', 'installation', ['action' => 'deleted'], 'not-the-app-secret')->assertUnauthorized();
    }

    public function test_app_uninstall_revokes_the_connection_and_flags_repositories(): void
    {
        $this->configureGitHubApp();
        [, $organization] = $this->memberOf(Role::Owner);
        $connection = $this->appConnection($organization, 4242);
        $repository = $this->linkedRepository($this->createProject($organization), $connection);

        $this->deliverWebhook('/api/webhooks/github', 'installation', [
            'action' => 'deleted',
            'installation' => ['id' => 4242],
            'sender' => ['login' => 'acme-admin'],
        ], 'app-webhook-secret')->assertStatus(202);

        $this->assertSame('revoked', $connection->refresh()->status->value);
        $this->assertSame('error', $repository->refresh()->connection_status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'git_connection.deleted', 'actor_type' => 'system', 'organization_id' => $organization->id]);
    }

    public function test_app_deliveries_route_to_every_linked_repository_and_deliveries_are_listed(): void
    {
        $this->configureGitHubApp();
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $connection = $this->appConnection($organization, 4242);
        $repository = $this->linkedRepository($this->createProject($organization), $connection);

        $this->deliverWebhook('/api/webhooks/github', 'pull_request', [
            'action' => 'opened',
            'installation' => ['id' => 4242],
            'repository' => ['id' => 1001],
            'pull_request' => $this->githubPullRequest(4),
        ], 'app-webhook-secret')->assertStatus(202);

        $this->assertSame($repository->id, PullRequest::withoutGlobalScopes()->sole()->repository_id);

        // Unknown installations are kept for debugging but never processed.
        $this->deliverWebhook('/api/webhooks/github', 'pull_request', [
            'action' => 'opened',
            'installation' => ['id' => 9999],
            'repository' => ['id' => 1001],
            'pull_request' => $this->githubPullRequest(5),
        ], 'app-webhook-secret')->assertStatus(202);

        $this->assertDatabaseCount('pull_requests', 1);

        $this->actingInOrganization($owner, $organization)
            ->getJson("/api/repositories/{$repository->id}/webhook-deliveries")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'pull_request')
            ->assertJsonPath('data.0.status', 'processed')
            ->assertJsonMissingPath('data.0.payload');
    }
}
