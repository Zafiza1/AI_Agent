<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_project_registers_repository_and_default_environments(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);

        $response = $this->actingInOrganization($owner, $organization)->postJson('/api/projects', [
            'name' => 'SIG Website',
            'description' => 'Public website',
            'repository_url' => 'https://github.com/acme/sig-website.git',
            'default_branch' => 'main',
            'framework' => 'Laravel',
            'language' => 'PHP',
            'database_type' => 'mysql',
            'deployment_type' => 'docker',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'sig-website')
            ->assertJsonPath('data.status', 'onboarding')
            ->assertJsonPath('data.repository_provider', 'github')
            ->assertJsonPath('data.primary_repository.full_name', 'acme/sig-website')
            ->assertJsonPath('data.counts.environments', 3);

        $environments = collect($response->json('data.environments'))->keyBy('type');
        $this->assertTrue($environments['production']['is_protected']);
        $this->assertTrue($environments['production']['requires_approval']);
        $this->assertFalse($environments['development']['is_protected']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'project.created', 'project_id' => $response->json('data.id')]);
    }

    public function test_default_environments_can_be_skipped(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);

        $this->actingInOrganization($owner, $organization)
            ->postJson('/api/projects', ['name' => 'Bare', 'create_default_environments' => false])
            ->assertCreated()
            ->assertJsonPath('data.counts.environments', 0)
            ->assertJsonPath('data.repository_url', null);
    }

    public function test_project_input_is_validated(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);

        $this->actingInOrganization($owner, $organization)
            ->postJson('/api/projects', ['name' => '', 'repository_url' => 'not a url', 'status' => 'exploded', 'default_branch' => 'bad branch;rm'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'repository_url', 'status', 'default_branch']);
    }

    public function test_role_permissions_on_projects(): void
    {
        [$viewer, $organization] = $this->memberOf(Role::Viewer);
        [$maintainer] = $this->memberOf(Role::Maintainer, $organization);
        [$admin] = $this->memberOf(Role::Admin, $organization);
        $this->memberOf(Role::Owner, $organization);

        $this->actingInOrganization($viewer, $organization)->postJson('/api/projects', ['name' => 'Nope'])->assertForbidden();
        $this->actingInOrganization($viewer, $organization)->getJson('/api/projects')->assertOk();

        $id = $this->actingInOrganization($maintainer, $organization)
            ->postJson('/api/projects', ['name' => 'Maintained'])
            ->assertCreated()
            ->json('data.id');

        $this->actingInOrganization($maintainer, $organization)->patchJson("/api/projects/{$id}", ['status' => 'active'])->assertOk();
        $this->actingInOrganization($maintainer, $organization)->deleteJson("/api/projects/{$id}")->assertForbidden();
        $this->actingInOrganization($admin, $organization)->deleteJson("/api/projects/{$id}")->assertOk();

        $this->assertSoftDeleted('projects', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'project.deleted', 'risk_level' => 'high']);
    }

    public function test_updating_repository_url_updates_the_primary_repository(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $project = $this->createProject($organization, ['repository_url' => 'https://github.com/acme/old']);

        $this->actingInOrganization($owner, $organization)
            ->patchJson("/api/projects/{$project->id}", [
                'repository_url' => 'https://gitlab.com/acme/new',
                'default_branch' => 'develop',
            ])
            ->assertOk()
            ->assertJsonPath('data.repository_url', 'https://gitlab.com/acme/new')
            ->assertJsonPath('data.repository_provider', 'gitlab')
            ->assertJsonPath('data.default_branch', 'develop');

        $this->assertSame(1, $project->repositories()->withoutGlobalScopes()->count());
    }

    public function test_projects_can_be_filtered_and_searched(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $this->createProject($organization, ['name' => 'Billing API', 'status' => 'active']);
        $this->createProject($organization, ['name' => 'Marketing Site', 'status' => 'archived']);

        $client = $this->actingInOrganization($owner, $organization);

        $client->getJson('/api/projects?search=billing')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Billing API');
        $client->getJson('/api/projects?status=archived')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Marketing Site');
        $client->getJson('/api/projects')->assertJsonPath('meta.total', 2);
    }

    public function test_repositories_can_be_added_and_primary_switched(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $project = $this->createProject($organization);

        $client = $this->actingInOrganization($owner, $organization);

        $repoId = $client->postJson("/api/projects/{$project->id}/repositories", [
            'url' => 'https://github.com/acme/worker',
            'is_primary' => true,
        ])->assertCreated()->assertJsonPath('data.is_primary', true)->json('data.id');

        $client->getJson("/api/projects/{$project->id}/repositories")
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $repoId);

        $client->postJson("/api/projects/{$project->id}/repositories", ['url' => 'https://github.com/acme/worker'])
            ->assertUnprocessable();
    }

    public function test_slug_reuse_within_an_organization_is_avoided(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $client = $this->actingInOrganization($owner, $organization);

        $client->postJson('/api/projects', ['name' => 'API'])->assertJsonPath('data.slug', 'api');
        $client->postJson('/api/projects', ['name' => 'API'])->assertJsonPath('data.slug', 'api-2');

        $this->assertSame(2, Project::withoutGlobalScopes()->count());
    }
}
