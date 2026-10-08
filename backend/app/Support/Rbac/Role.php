<?php

namespace App\Support\Rbac;

/**
 * System roles of an organization membership, ordered from most to least privileged.
 */
enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Maintainer = 'maintainer';
    case Viewer = 'viewer';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Admin => array_values(array_filter(
                Permission::cases(),
                fn (Permission $p) => $p !== Permission::OrganizationDelete,
            )),
            self::Maintainer => [
                Permission::OrganizationView,
                Permission::MembersView,
                Permission::ProjectsView,
                Permission::ProjectsCreate,
                Permission::ProjectsUpdate,
                Permission::EnvironmentsManage,
                Permission::InfrastructureManage,
                Permission::RepositoriesWrite,
            ],
            self::Viewer => [
                Permission::OrganizationView,
                Permission::MembersView,
                Permission::ProjectsView,
            ],
        };
    }

    public function allows(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Whether a member with this role may assign or modify a member holding $target.
     * Only owners may grant or touch ownership.
     */
    public function canManageRole(self $target): bool
    {
        if (! $this->allows(Permission::MembersManage)) {
            return false;
        }

        return $target !== self::Owner || $this === self::Owner;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
