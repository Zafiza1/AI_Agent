<?php

namespace App\Http\Resources;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Support\Rbac\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Includes the viewing user's role and effective permissions when a membership is
 * available, either as the pivot (User::organizations()) or via withMembership().
 *
 * @mixin Organization
 */
class OrganizationResource extends JsonResource
{
    private ?OrganizationMember $membership = null;

    public function withMembership(OrganizationMember $membership): static
    {
        $this->membership = $membership;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $membership = $this->membership ?? ($this->resource->relationLoaded('pivot') ? $this->resource->pivot : null);
        $role = $membership?->role;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'settings' => $this->settings ?? (object) [],
            'role' => $role?->value,
            'permissions' => $role ? array_map(fn (Permission $p) => $p->value, $role->permissions()) : [],
            'members_count' => $this->whenCounted('members'),
            'projects_count' => $this->whenCounted('projects'),
            'created_at' => $this->created_at,
        ];
    }
}
