<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_becomes_owner_of_a_new_organization(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/organizations', ['name' => 'Acme Engineering'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'acme-engineering')
            ->assertJsonPath('data.role', 'owner');

        $this->assertContains('organization.delete', $response->json('data.permissions'));
        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $response->json('data.id'),
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
    }

    public function test_slugs_are_unique(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/organizations', ['name' => 'Acme'])->assertJsonPath('data.slug', 'acme');
        $this->postJson('/api/organizations', ['name' => 'Acme'])->assertJsonPath('data.slug', 'acme-2');
    }

    public function test_index_lists_only_my_organizations(): void
    {
        [$user, $mine] = $this->memberOf(Role::Viewer);
        $this->memberOf(Role::Owner); // someone else's

        Sanctum::actingAs($user);

        $this->getJson('/api/organizations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.role', 'viewer');
    }

    public function test_tenant_routes_require_a_valid_organization_header(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/projects')->assertStatus(400);
        $this->withHeader('X-Organization-Id', 'not-a-uuid')->getJson('/api/projects')->assertStatus(400);
    }

    public function test_non_members_get_the_same_forbidden_response_for_existing_and_missing_organizations(): void
    {
        [, $other] = $this->memberOf();
        Sanctum::actingAs(User::factory()->create());

        $existing = $this->withHeader('X-Organization-Id', $other->id)->getJson('/api/organization');
        $missing = $this->withHeader('X-Organization-Id', (string) Str::uuid())->getJson('/api/organization');

        $existing->assertForbidden();
        $missing->assertForbidden();
        $this->assertSame($existing->json(), $missing->json());
    }

    public function test_current_organization_returns_role_and_permissions(): void
    {
        [$user, $organization] = $this->memberOf(Role::Maintainer);

        $this->actingInOrganization($user, $organization)
            ->getJson('/api/organization')
            ->assertOk()
            ->assertJsonPath('data.role', 'maintainer')
            ->assertJsonPath('data.members_count', 1);
    }

    public function test_only_admins_and_owners_can_update_the_organization(): void
    {
        [$viewer, $organization] = $this->memberOf(Role::Viewer);
        [$admin] = $this->memberOf(Role::Admin, $organization);

        $this->actingInOrganization($viewer, $organization)
            ->patchJson('/api/organization', ['name' => 'Renamed'])
            ->assertForbidden();

        $this->actingInOrganization($admin, $organization)
            ->patchJson('/api/organization', ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_only_owner_can_delete_and_must_confirm_with_slug(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        [$admin] = $this->memberOf(Role::Admin, $organization);

        $this->actingInOrganization($admin, $organization)
            ->deleteJson('/api/organization', ['confirm' => $organization->slug])
            ->assertForbidden();

        $this->actingInOrganization($owner, $organization)
            ->deleteJson('/api/organization', ['confirm' => 'wrong'])
            ->assertUnprocessable();

        $this->actingInOrganization($owner, $organization)
            ->deleteJson('/api/organization', ['confirm' => $organization->slug])
            ->assertOk();

        $this->assertModelMissing($organization);
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.deleted', 'organization_id' => $organization->id, 'risk_level' => 'critical']);
    }

    public function test_meta_exposes_role_permission_matrix(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/meta')
            ->assertOk()
            ->assertJsonPath('data.roles.0.value', 'owner')
            ->assertJsonPath('data.enums.environment_type', ['development', 'staging', 'production']);
    }

    public function test_organization_model_factory_creates_unique_slugs(): void
    {
        $this->assertNotSame(Organization::factory()->create()->slug, Organization::factory()->create()->slug);
    }
}
