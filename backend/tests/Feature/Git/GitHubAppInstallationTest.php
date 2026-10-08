<?php

namespace Tests\Feature\Git;

use App\Models\GitConnection;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithGitHub;
use Tests\TestCase;

class GitHubAppInstallationTest extends TestCase
{
    use InteractsWithGitHub, RefreshDatabase;

    private const INSTALLATION = 4242;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureGitHubApp();
    }

    /**
     * Start the flow and return the state GitHub would echo back.
     */
    private function startInstallation($user, $organization): string
    {
        $url = $this->actingInOrganization($user, $organization)
            ->postJson('/api/git-connections/github/install')
            ->assertOk()
            ->json('data.url');

        $this->assertStringStartsWith('https://github.com/apps/maintenance-platform/installations/new?state=', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    private function fakeGitHub(array $userInstallations = [self::INSTALLATION]): void
    {
        Http::fake([
            'https://github.com/login/oauth/access_token' => Http::response(['access_token' => 'ghu_usertoken']),
            self::API.'/user/installations*' => Http::response([
                'total_count' => count($userInstallations),
                'installations' => array_map(fn ($id) => ['id' => $id], $userInstallations),
            ]),
            self::API.'/app/installations/'.self::INSTALLATION => Http::response([
                'id' => self::INSTALLATION,
                'account' => ['login' => 'acme', 'type' => 'Organization'],
                'permissions' => ['contents' => 'write', 'pull_requests' => 'write'],
                'suspended_at' => null,
            ]),
        ]);
    }

    public function test_installation_is_linked_after_state_and_user_access_are_verified(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $state = $this->startInstallation($owner, $organization);
        $this->fakeGitHub();

        $this->actingInOrganization($owner, $organization)
            ->postJson('/api/git-connections/github/callback', [
                'installation_id' => self::INSTALLATION,
                'setup_action' => 'install',
                'code' => 'oauth-code',
                'state' => $state,
            ])
            ->assertCreated()
            ->assertJsonPath('data.auth_type', 'github_app')
            ->assertJsonPath('data.account_login', 'acme')
            ->assertJsonPath('data.account_type', 'organization')
            ->assertJsonPath('data.installation_id', self::INSTALLATION);

        // The app-level call is authenticated with a signed RS256 JWT.
        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/app/installations/'.self::INSTALLATION)) {
                return false;
            }

            [$header] = explode('.', substr($request->header('Authorization')[0], 7));

            return json_decode(base64_decode(strtr($header, '-_', '+/')), true)['alg'] === 'RS256';
        });

        $this->assertDatabaseHas('audit_logs', ['action' => 'git_connection.installed', 'organization_id' => $organization->id]);
    }

    public function test_state_is_single_use_and_bound_to_the_user(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        [$admin] = $this->memberOf(Role::Admin, $organization);
        $state = $this->startInstallation($owner, $organization);
        $this->fakeGitHub();

        $body = ['installation_id' => self::INSTALLATION, 'code' => 'oauth-code', 'state' => $state];

        // Another member cannot complete someone else's flow (and consuming it burns the state).
        $this->actingInOrganization($admin, $organization)
            ->postJson('/api/git-connections/github/callback', $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['state']);

        $this->actingInOrganization($owner, $organization)
            ->postJson('/api/git-connections/github/callback', $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['state']);

        $this->assertDatabaseCount('git_connections', 0);
    }

    public function test_a_user_cannot_link_an_installation_they_cannot_access(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $state = $this->startInstallation($owner, $organization);
        $this->fakeGitHub(userInstallations: [999]);

        $this->actingInOrganization($owner, $organization)
            ->postJson('/api/git-connections/github/callback', ['installation_id' => self::INSTALLATION, 'code' => 'oauth-code', 'state' => $state])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['installation_id']);

        $this->assertDatabaseCount('git_connections', 0);
    }

    public function test_the_oauth_code_is_required(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $state = $this->startInstallation($owner, $organization);
        $this->fakeGitHub();

        $this->actingInOrganization($owner, $organization)
            ->postJson('/api/git-connections/github/callback', ['installation_id' => self::INSTALLATION, 'state' => $state])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_an_installation_belongs_to_one_organization_only(): void
    {
        [, $first] = $this->memberOf(Role::Owner);
        $this->appConnection($first, self::INSTALLATION);

        [$owner, $organization] = $this->memberOf(Role::Owner);
        $state = $this->startInstallation($owner, $organization);
        $this->fakeGitHub();

        $this->actingInOrganization($owner, $organization)
            ->postJson('/api/git-connections/github/callback', ['installation_id' => self::INSTALLATION, 'code' => 'oauth-code', 'state' => $state])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['installation_id']);

        $this->assertSame($first->id, GitConnection::withoutGlobalScopes()->sole()->organization_id);
    }

    public function test_install_requests_awaiting_approval_are_acknowledged(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        Http::fake();

        $this->actingInOrganization($owner, $organization)
            ->postJson('/api/git-connections/github/callback', ['setup_action' => 'request', 'state' => 'anything'])
            ->assertStatus(202);

        Http::assertNothingSent();
    }

    public function test_installation_tokens_are_minted_and_cached(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $connection = $this->appConnection($organization, self::INSTALLATION);
        Http::fake([
            self::API.'/app/installations/'.self::INSTALLATION.'/access_tokens' => Http::response(['token' => 'ghs_installation', 'expires_at' => now()->addHour()->toIso8601String()], 201),
            self::API.'/installation/repositories*' => Http::response(['total_count' => 1, 'repositories' => [$this->githubRepository()]]),
        ]);

        foreach ([1, 2] as $attempt) {
            $this->actingInOrganization($owner, $organization)
                ->getJson("/api/git-connections/{$connection->id}/remote-repositories")
                ->assertOk()
                ->assertJsonPath('data.0.full_name', 'acme/api');
        }

        Http::assertSentCount(3); // one token mint, two listings
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/installation/repositories') && $r->hasHeader('Authorization', 'Bearer ghs_installation'));
    }
}
