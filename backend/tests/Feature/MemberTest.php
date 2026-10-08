<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_an_existing_user_as_member(): void
    {
        [$admin, $organization] = $this->memberOf(Role::Admin);
        $newcomer = User::factory()->create(['email' => 'new@example.com']);

        $this->actingInOrganization($admin, $organization)
            ->postJson('/api/organization/members', ['email' => 'new@example.com', 'role' => 'maintainer'])
            ->assertCreated()
            ->assertJsonPath('data.user.id', $newcomer->id)
            ->assertJsonPath('data.role', 'maintainer');

        $this->assertDatabaseHas('audit_logs', ['action' => 'member.added', 'organization_id' => $organization->id]);
    }

    public function test_adding_unknown_or_existing_members_fails_validation(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);

        $this->actingInOrganization($owner, $organization)
            ->postJson('/api/organization/members', ['email' => 'ghost@example.com', 'role' => 'viewer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->actingInOrganization($owner, $organization)
            ->postJson('/api/organization/members', ['email' => $owner->email, 'role' => 'viewer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_viewers_and_maintainers_cannot_manage_members(): void
    {
        [$maintainer, $organization] = $this->memberOf(Role::Maintainer);
        User::factory()->create(['email' => 'new@example.com']);

        $this->actingInOrganization($maintainer, $organization)
            ->postJson('/api/organization/members', ['email' => 'new@example.com', 'role' => 'viewer'])
            ->assertForbidden();

        $this->actingInOrganization($maintainer, $organization)
            ->getJson('/api/organization/members')
            ->assertOk();
    }

    public function test_only_owners_can_grant_ownership(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        [$admin] = $this->memberOf(Role::Admin, $organization);
        [$viewer] = $this->memberOf(Role::Viewer, $organization);
        $viewerMembership = $organization->members()->where('user_id', $viewer->id)->sole();

        $this->actingInOrganization($admin, $organization)
            ->patchJson("/api/organization/members/{$viewerMembership->id}", ['role' => 'owner'])
            ->assertForbidden();

        $this->actingInOrganization($owner, $organization)
            ->patchJson("/api/organization/members/{$viewerMembership->id}", ['role' => 'owner'])
            ->assertOk()
            ->assertJsonPath('data.role', 'owner');
    }

    public function test_admin_cannot_remove_an_owner(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        [$admin] = $this->memberOf(Role::Admin, $organization);
        $ownerMembership = $organization->members()->where('user_id', $owner->id)->sole();

        $this->actingInOrganization($admin, $organization)
            ->deleteJson("/api/organization/members/{$ownerMembership->id}")
            ->assertForbidden();
    }

    public function test_the_last_owner_cannot_be_demoted_or_leave(): void
    {
        [$owner, $organization] = $this->memberOf(Role::Owner);
        $membership = $organization->members()->where('user_id', $owner->id)->sole();

        $this->actingInOrganization($owner, $organization)
            ->patchJson("/api/organization/members/{$membership->id}", ['role' => 'admin'])
            ->assertUnprocessable();

        $this->actingInOrganization($owner, $organization)
            ->deleteJson("/api/organization/members/{$membership->id}")
            ->assertUnprocessable();
    }

    public function test_a_member_can_leave(): void
    {
        [, $organization] = $this->memberOf(Role::Owner);
        [$viewer] = $this->memberOf(Role::Viewer, $organization);
        $membership = $organization->members()->where('user_id', $viewer->id)->sole();

        $this->actingInOrganization($viewer, $organization)
            ->deleteJson("/api/organization/members/{$membership->id}")
            ->assertOk();

        $this->assertDatabaseMissing('organization_members', ['id' => $membership->id]);
    }
}
