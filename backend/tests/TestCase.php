<?php

namespace Tests;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectRegistrar;
use App\Support\Rbac\Role;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Runs after the application boots and before RefreshDatabase wipes the schema.
     * Refuse any database that is not in-memory or explicitly named for tests, so a
     * misconfigured environment can never destroy development or production data.
     */
    protected function setUpTraits()
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if ($database !== ':memory:' && ! str_contains(strtolower(basename($database)), 'test')) {
            throw new RuntimeException("Refusing to run tests against database [{$database}]: use :memory: or a database named like *test*.");
        }

        return parent::setUpTraits();
    }

    /**
     * Create an organization with a member holding $role.
     *
     * @return array{0: User, 1: Organization}
     */
    protected function memberOf(Role $role = Role::Owner, ?Organization $organization = null): array
    {
        $user = User::factory()->create();

        if ($organization) {
            $organization->members()->create(['user_id' => $user->id, 'role' => $role]);
        } else {
            $organization = Organization::factory()->withMember($user, $role)->create();
        }

        return [$user, $organization];
    }

    /**
     * Authenticate as $user and act inside $organization for following requests.
     */
    protected function actingInOrganization(User $user, Organization $organization): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Organization-Id', $organization->id);
    }

    /**
     * Create a project through the real registrar (repository + default environments).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createProject(Organization $organization, array $attributes = []): Project
    {
        $tenant = app(CurrentOrganization::class);
        $owner = $organization->members()->where('role', Role::Owner->value)->firstOrFail();
        $tenant->set($organization, $owner);

        try {
            return app(ProjectRegistrar::class)->create([
                'name' => 'Project '.fake()->unique()->numberBetween(1, 1_000_000),
                'repository_url' => 'https://github.com/acme/'.fake()->unique()->slug(2),
                ...$attributes,
            ], $owner->user);
        } finally {
            $tenant->clear();
        }
    }
}
