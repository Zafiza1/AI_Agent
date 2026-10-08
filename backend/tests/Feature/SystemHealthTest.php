<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_each_dependency(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Redis::shouldReceive('connection->ping')->andReturn(true);
        Http::fake(['*/health' => Http::response(['status' => 'ok'])]);

        $this->getJson('/api/system/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'healthy')
            ->assertJsonPath('data.checks.database.status', 'up')
            ->assertJsonPath('data.checks.redis.status', 'up')
            ->assertJsonPath('data.checks.agent.status', 'up');
    }

    public function test_reports_degraded_without_leaking_errors(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Redis::shouldReceive('connection->ping')->andThrow(new RuntimeException('tcp://10.0.0.9:6379 refused'));
        Http::fake(['*/health' => Http::response([], 503)]);

        $response = $this->getJson('/api/system/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.checks.redis.status', 'down')
            ->assertJsonPath('data.checks.agent.status', 'down');

        $this->assertStringNotContainsString('10.0.0.9', $response->getContent());
    }
}
