<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Support\Rbac\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
        ];
    }

    /**
     * Attach $user as a member with $role after creation.
     */
    public function withMember(User $user, Role $role = Role::Owner): static
    {
        return $this->afterCreating(function (Organization $organization) use ($user, $role) {
            OrganizationMember::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => $role,
            ]);
        });
    }
}
