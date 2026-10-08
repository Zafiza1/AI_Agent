<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-42',
            'password_confirmation' => 'correct-horse-42',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'name', 'email'], 'organizations'])
            ->assertJsonMissingPath('user.password');

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.registered']);
    }

    public function test_registration_enforces_password_policy_and_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/auth/register', [
            'name' => 'X',
            'email' => 'taken@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_user_can_log_in_and_fetch_profile_with_token(): void
    {
        $user = User::factory()->create(['email' => 'dev@example.com']);

        $token = $this->postJson('/api/auth/login', ['email' => 'dev@example.com', 'password' => 'password'])
            ->assertOk()
            ->json('token');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_failed_login_is_rejected_and_audited_without_the_password(): void
    {
        User::factory()->create(['email' => 'dev@example.com']);

        $this->postJson('/api/auth/login', ['email' => 'dev@example.com', 'password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $log = AuditLog::where('action', 'auth.login_failed')->sole();
        $this->assertSame('failure', $log->result->value);
        $this->assertStringNotContainsString('wrong-password', json_encode($log->metadata));
    }

    public function test_logout_revokes_the_token(): void
    {
        User::factory()->create(['email' => 'dev@example.com']);
        $token = $this->postJson('/api/auth/login', ['email' => 'dev@example.com', 'password' => 'password'])->json('token');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_protected_endpoints_require_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->getJson('/api/projects')->assertUnauthorized();
    }

    public function test_login_is_rate_limited(): void
    {
        foreach (range(1, 10) as $_) {
            $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'x']);
        }

        $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'x'])
            ->assertTooManyRequests();
    }
}
