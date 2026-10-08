<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Support\Rbac\Permission;
use App\Support\Rbac\Role;
use LogicException;

/**
 * Request-scoped holder of the organization the authenticated user is acting in.
 * It is only ever populated by ResolveOrganization after a membership check.
 */
class CurrentOrganization
{
    private ?Organization $organization = null;

    private ?OrganizationMember $membership = null;

    public function set(Organization $organization, OrganizationMember $membership): void
    {
        $this->organization = $organization;
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->organization = null;
        $this->membership = null;
    }

    public function has(): bool
    {
        return $this->organization !== null;
    }

    public function get(): Organization
    {
        return $this->organization ?? throw new LogicException('No organization resolved for this request.');
    }

    public function id(): ?string
    {
        return $this->organization?->id;
    }

    public function membership(): OrganizationMember
    {
        return $this->membership ?? throw new LogicException('No organization resolved for this request.');
    }

    public function role(): ?Role
    {
        return $this->membership?->role;
    }

    public function can(Permission $permission): bool
    {
        return $this->role()?->allows($permission) ?? false;
    }
}
