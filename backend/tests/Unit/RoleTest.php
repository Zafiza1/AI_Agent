<?php

namespace Tests\Unit;

use App\Support\Rbac\Permission;
use App\Support\Rbac\Role;
use PHPUnit\Framework\TestCase;

class RoleTest extends TestCase
{
    public function test_owner_has_every_permission(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertTrue(Role::Owner->allows($permission), $permission->value);
        }
    }

    public function test_admin_cannot_delete_the_organization(): void
    {
        $this->assertFalse(Role::Admin->allows(Permission::OrganizationDelete));
        $this->assertTrue(Role::Admin->allows(Permission::EnvironmentsManageProtected));
    }

    public function test_maintainer_cannot_touch_protected_environments_secrets_or_members(): void
    {
        $this->assertFalse(Role::Maintainer->allows(Permission::EnvironmentsManageProtected));
        $this->assertFalse(Role::Maintainer->allows(Permission::SecretsManage));
        $this->assertFalse(Role::Maintainer->allows(Permission::MembersManage));
        $this->assertFalse(Role::Maintainer->allows(Permission::ProjectsDelete));
        $this->assertTrue(Role::Maintainer->allows(Permission::EnvironmentsManage));
    }

    public function test_viewer_is_read_only(): void
    {
        foreach (Role::Viewer->permissions() as $permission) {
            $this->assertMatchesRegularExpression('/\.view$/', $permission->value);
        }
    }

    public function test_only_owners_can_manage_the_owner_role(): void
    {
        $this->assertTrue(Role::Owner->canManageRole(Role::Owner));
        $this->assertFalse(Role::Admin->canManageRole(Role::Owner));
        $this->assertTrue(Role::Admin->canManageRole(Role::Maintainer));
        $this->assertFalse(Role::Maintainer->canManageRole(Role::Viewer));
    }
}
