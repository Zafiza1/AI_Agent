<?php

namespace Tests\Feature;

use App\Models\Environment;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_maintainer_can_register_servers_databases_and_services(): void
    {
        [$maintainer, $organization] = $this->memberOf(Role::Maintainer);
        $this->memberOf(Role::Owner, $organization);
        $project = $this->createProject($organization);
        $production = Environment::withoutGlobalScopes()->where('project_id', $project->id)->where('type', 'production')->firstOrFail();

        $client = $this->actingInOrganization($maintainer, $organization);

        $serverId = $client->postJson("/api/projects/{$project->id}/servers", [
            'name' => 'web-01',
            'hostname' => 'web-01.example.com',
            'ip_address' => '10.0.0.5',
            'environment_id' => $production->id,
            'connection_type' => 'ssh',
        ])->assertCreated()->assertJsonPath('data.status', 'unknown')->json('data.id');

        $client->postJson("/api/projects/{$project->id}/databases", [
            'name' => 'main-db',
            'engine' => 'postgresql',
            'port' => 5432,
            'server_id' => $serverId,
        ])->assertCreated()->assertJsonPath('data.engine', 'postgresql');

        $serviceId = $client->postJson("/api/projects/{$project->id}/services", [
            'name' => 'api',
            'type' => 'api',
            'runtime' => 'docker',
            'container_name' => 'acme-api',
            'port' => 8000,
            'server_id' => $serverId,
        ])->assertCreated()->json('data.id');

        $client->patchJson("/api/services/{$serviceId}", ['status' => 'healthy'])->assertOk()->assertJsonPath('data.status', 'healthy');
        $client->getJson("/api/projects/{$project->id}")
            ->assertJsonPath('data.counts.servers', 1)
            ->assertJsonPath('data.counts.databases', 1)
            ->assertJsonPath('data.counts.services', 1);

        $client->deleteJson("/api/servers/{$serverId}")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'server.deleted']);
    }

    public function test_infrastructure_input_is_validated(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $project = $this->createProject($organization);

        $this->actingInOrganization($owner, $organization)
            ->postJson("/api/projects/{$project->id}/databases", ['name' => 'db', 'engine' => 'excel', 'port' => 70000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['engine', 'port']);

        $this->actingInOrganization($owner, $organization)
            ->postJson("/api/projects/{$project->id}/services", ['name' => 'svc', 'type' => 'api', 'container_name' => 'x; rm -rf /'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['container_name']);
    }

    public function test_viewer_cannot_change_infrastructure(): void
    {
        [$viewer, $organization] = $this->memberOf(Role::Viewer);
        $this->memberOf(Role::Owner, $organization);
        $project = $this->createProject($organization);

        $this->actingInOrganization($viewer, $organization)
            ->postJson("/api/projects/{$project->id}/servers", ['name' => 'web-01'])
            ->assertForbidden();

        $this->actingInOrganization($viewer, $organization)
            ->getJson("/api/projects/{$project->id}/servers")
            ->assertOk();
    }

    public function test_dashboard_overview_counts_the_current_organization(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        [, $other] = $this->memberOf(Role::Owner);
        $this->createProject($organization);
        $this->createProject($other);

        $this->actingInOrganization($owner, $organization)
            ->getJson('/api/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('data.projects.total', 1)
            ->assertJsonPath('data.environments.total', 3)
            ->assertJsonPath('data.environments.protected', 1)
            ->assertJsonPath('data.members', 1);
    }
}
