<?php

namespace Tests\Feature;

use App\Contracts\SecretStore;
use App\Models\AuditLog;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnvironmentTest extends TestCase
{
    use RefreshDatabase;

    private function environment(string $projectId, string $type): Environment
    {
        return Environment::withoutGlobalScopes()->where('project_id', $projectId)->where('type', $type)->firstOrFail();
    }

    public function test_maintainer_can_change_staging_but_not_production(): void
    {
        [$maintainer, $organization] = $this->memberOf(Role::Maintainer);
        $this->memberOf(Role::Owner, $organization);
        $project = $this->createProject($organization);

        $staging = $this->environment($project->id, 'staging');
        $production = $this->environment($project->id, 'production');

        $client = $this->actingInOrganization($maintainer, $organization);

        $client->patchJson("/api/environments/{$staging->id}", ['url' => 'https://staging.example.com'])
            ->assertOk()
            ->assertJsonPath('data.url', 'https://staging.example.com');

        $client->patchJson("/api/environments/{$production->id}", ['url' => 'https://evil.example.com'])->assertForbidden();
        $client->deleteJson("/api/environments/{$production->id}")->assertForbidden();
    }

    public function test_changing_protection_flags_requires_protected_permission(): void
    {
        [$maintainer, $organization] = $this->memberOf(Role::Maintainer);
        [$admin] = $this->memberOf(Role::Admin, $organization);
        $this->memberOf(Role::Owner, $organization);
        $project = $this->createProject($organization);

        $staging = $this->environment($project->id, 'staging');
        $production = $this->environment($project->id, 'production');

        $this->actingInOrganization($maintainer, $organization)
            ->patchJson("/api/environments/{$staging->id}", ['requires_approval' => true])
            ->assertForbidden();

        $this->actingInOrganization($admin, $organization)
            ->patchJson("/api/environments/{$production->id}", ['requires_approval' => false])
            ->assertOk()
            ->assertJsonPath('data.requires_approval', false);

        $this->assertDatabaseHas('audit_logs', ['action' => 'environment.updated', 'risk_level' => 'high']);
    }

    public function test_creating_a_production_environment_requires_protected_permission(): void
    {
        [$maintainer, $organization] = $this->memberOf(Role::Maintainer);
        $this->memberOf(Role::Owner, $organization);
        $project = $this->createProject($organization, ['create_default_environments' => false]);

        $client = $this->actingInOrganization($maintainer, $organization);

        $client->postJson("/api/projects/{$project->id}/environments", ['name' => 'prod-eu', 'type' => 'production'])->assertForbidden();
        $client->postJson("/api/projects/{$project->id}/environments", ['name' => 'qa', 'type' => 'staging'])
            ->assertCreated()
            ->assertJsonPath('data.is_protected', false);
        $client->postJson("/api/projects/{$project->id}/environments", ['name' => 'qa', 'type' => 'staging'])->assertUnprocessable();
    }

    public function test_secret_values_are_encrypted_and_never_returned(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $project = $this->createProject($organization);
        $staging = $this->environment($project->id, 'staging');

        $response = $this->actingInOrganization($owner, $organization)
            ->putJson("/api/environments/{$staging->id}/variables", [
                'variables' => [
                    ['key' => 'DB_PASSWORD', 'value' => 'super-secret-value', 'is_secret' => true],
                    ['key' => 'APP_ENV', 'value' => 'staging', 'is_secret' => false],
                ],
            ])
            ->assertOk();

        $this->assertStringNotContainsString('super-secret-value', $response->getContent());
        $variables = collect($response->json('data'))->keyBy('key');
        $this->assertNull($variables['DB_PASSWORD']['value']);
        $this->assertSame('staging', $variables['APP_ENV']['value']);

        $raw = DB::table('environment_variables')->where('key', 'DB_PASSWORD')->value('value');
        $this->assertNotSame('super-secret-value', $raw);
        $this->assertSame('super-secret-value', app(SecretStore::class)->reveal(EnvironmentVariable::withoutGlobalScopes()->where('key', 'DB_PASSWORD')->sole()));

        $audit = AuditLog::where('action', 'environment.variables_updated')->sole();
        $this->assertSame(['DB_PASSWORD', 'APP_ENV'], $audit->metadata['keys']);
        $this->assertStringNotContainsString('super-secret-value', json_encode($audit->metadata));

        $this->actingInOrganization($owner, $organization)
            ->getJson("/api/environments/{$staging->id}/variables")
            ->assertOk()
            ->assertJsonMissing(['value' => 'super-secret-value']);
    }

    public function test_variables_are_upserted_by_key(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $project = $this->createProject($organization);
        $staging = $this->environment($project->id, 'staging');
        $client = $this->actingInOrganization($owner, $organization);

        $client->putJson("/api/environments/{$staging->id}/variables", ['variables' => [['key' => 'API_URL', 'value' => 'a', 'is_secret' => false]]]);
        $client->putJson("/api/environments/{$staging->id}/variables", ['variables' => [['key' => 'API_URL', 'value' => 'b', 'is_secret' => false]]])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.value', 'b');

        $client->putJson("/api/environments/{$staging->id}/variables", ['variables' => [['key' => 'lower-case', 'value' => 'x']]])
            ->assertUnprocessable();
    }

    public function test_maintainers_cannot_manage_secrets(): void
    {
        [$maintainer, $organization] = $this->memberOf(Role::Maintainer);
        $this->memberOf(Role::Owner, $organization);
        $project = $this->createProject($organization);
        $development = $this->environment($project->id, 'development');

        $this->actingInOrganization($maintainer, $organization)
            ->putJson("/api/environments/{$development->id}/variables", ['variables' => [['key' => 'X', 'value' => 'y']]])
            ->assertForbidden();
    }

    public function test_variables_can_be_deleted(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $project = $this->createProject($organization);
        $staging = $this->environment($project->id, 'staging');
        $client = $this->actingInOrganization($owner, $organization);

        $id = $client->putJson("/api/environments/{$staging->id}/variables", ['variables' => [['key' => 'TOKEN', 'value' => 'x']]])
            ->json('data.0.id');

        $client->deleteJson("/api/environments/{$staging->id}/variables/{$id}")->assertOk();
        $this->assertDatabaseMissing('environment_variables', ['id' => $id]);
    }
}
