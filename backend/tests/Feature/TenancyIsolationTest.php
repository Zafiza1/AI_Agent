<?php

namespace Tests\Feature;

use App\Models\Environment;
use App\Models\Project;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-tenant access must be impossible through every path: spoofed headers,
 * foreign ids, request payloads and list endpoints.
 */
class TenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_foreign_organization_header_is_rejected(): void
    {
        [$alice] = $this->memberOf(Role::Owner);
        [, $globex] = $this->memberOf(Role::Owner);

        $this->actingInOrganization($alice, $globex)->getJson('/api/projects')->assertForbidden();
    }

    public function test_foreign_resource_ids_are_not_found_from_my_organization(): void
    {
        [$alice, $acme] = $this->memberOf(Role::Owner);
        [, $globex] = $this->memberOf(Role::Owner);

        $foreignProject = $this->createProject($globex);
        $foreignEnvironment = Environment::withoutGlobalScopes()->where('project_id', $foreignProject->id)->firstOrFail();

        $foreignServer = $foreignProject->servers()->make(['name' => 'globex-web']);
        $foreignServer->organization_id = $globex->id;
        $foreignServer->save();
        $foreignServerId = $foreignServer->id;

        $client = $this->actingInOrganization($alice, $acme);

        $client->getJson("/api/projects/{$foreignProject->id}")->assertNotFound();
        $client->patchJson("/api/projects/{$foreignProject->id}", ['name' => 'pwned'])->assertNotFound();
        $client->deleteJson("/api/projects/{$foreignProject->id}")->assertNotFound();
        $client->getJson("/api/environments/{$foreignEnvironment->id}")->assertNotFound();
        $client->getJson("/api/environments/{$foreignEnvironment->id}/variables")->assertNotFound();
        $client->putJson("/api/environments/{$foreignEnvironment->id}/variables", ['variables' => [['key' => 'X', 'value' => 'y']]])->assertNotFound();
        $client->getJson("/api/servers/{$foreignServerId}")->assertNotFound();
        $client->postJson("/api/projects/{$foreignProject->id}/servers", ['name' => 'evil'])->assertNotFound();

        $this->assertNotSame('pwned', Project::withoutGlobalScopes()->find($foreignProject->id)->name);
    }

    public function test_project_lists_only_contain_my_organization(): void
    {
        [$alice, $acme] = $this->memberOf(Role::Owner);
        [, $globex] = $this->memberOf(Role::Owner);

        $mine = $this->createProject($acme, ['name' => 'Mine']);
        $this->createProject($globex, ['name' => 'Theirs']);

        $this->actingInOrganization($alice, $acme)
            ->getJson('/api/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_organization_id_in_payload_is_ignored(): void
    {
        [$alice, $acme] = $this->memberOf(Role::Owner);
        [, $globex] = $this->memberOf(Role::Owner);

        $id = $this->actingInOrganization($alice, $acme)
            ->postJson('/api/projects', ['name' => 'Sneaky', 'organization_id' => $globex->id])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame($acme->id, Project::withoutGlobalScopes()->find($id)->organization_id);
    }

    public function test_sibling_ids_from_another_project_are_rejected(): void
    {
        [$alice, $acme] = $this->memberOf(Role::Owner);
        $projectA = $this->createProject($acme);
        $projectB = $this->createProject($acme);
        $environmentOfB = Environment::where('project_id', $projectB->id)->firstOrFail();

        $this->actingInOrganization($alice, $acme)
            ->postJson("/api/projects/{$projectA->id}/servers", ['name' => 'web-1', 'environment_id' => $environmentOfB->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('environment_id');

        $this->actingInOrganization($alice, $acme)
            ->postJson("/api/projects/{$projectA->id}/services", ['name' => 'api', 'type' => 'api', 'server_id' => 'not-a-uuid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('server_id');
    }

    public function test_audit_logs_only_show_my_organization(): void
    {
        [$alice, $acme] = $this->memberOf(Role::Owner);
        [$bob, $globex] = $this->memberOf(Role::Owner);

        $this->actingInOrganization($bob, $globex)->postJson('/api/projects', ['name' => 'Globex secret'])->assertCreated();
        $this->actingInOrganization($alice, $acme)->postJson('/api/projects', ['name' => 'Acme thing'])->assertCreated();

        $logs = $this->actingInOrganization($alice, $acme)->getJson('/api/audit-logs')->assertOk()->json('data');

        $this->assertNotEmpty($logs);
        foreach ($logs as $log) {
            $this->assertSame($acme->id, $log['organization_id']);
        }
    }

    public function test_malformed_ids_return_not_found(): void
    {
        [$alice, $acme] = $this->memberOf(Role::Owner);

        $this->actingInOrganization($alice, $acme)->getJson('/api/projects/123')->assertNotFound();
        $this->actingInOrganization($alice, $acme)->getJson('/api/servers/abc')->assertNotFound();
    }
}
