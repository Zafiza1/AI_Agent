<?php

namespace Database\Seeders;

use App\Contracts\SecretStore;
use App\Enums\DatabaseEngine;
use App\Enums\EnvironmentType;
use App\Enums\ProjectStatus;
use App\Enums\ResourceStatus;
use App\Enums\ServerConnectionType;
use App\Enums\ServiceRuntime;
use App\Enums\ServiceType;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Services\Projects\ProjectRegistrar;
use App\Support\Rbac\Role;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Development data: two isolated organizations, one user per role, and two
 * realistic projects with environments and infrastructure records.
 *
 * All seeded accounts use the password from SEED_USER_PASSWORD (default "password").
 */
class DatabaseSeeder extends Seeder
{
    public function run(ProjectRegistrar $registrar, SecretStore $secrets, CurrentOrganization $tenant): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The development seeder must not run in production.');
        }

        $password = env('SEED_USER_PASSWORD', 'password');

        $users = collect(Role::cases())->mapWithKeys(fn (Role $role) => [
            $role->value => User::updateOrCreate(
                ['email' => "{$role->value}@example.com"],
                ['name' => ucfirst($role->value).' User', 'password' => $password, 'email_verified_at' => now()],
            ),
        ]);

        $acme = Organization::updateOrCreate(['slug' => 'acme-engineering'], ['name' => 'Acme Engineering']);

        foreach ($users as $role => $user) {
            OrganizationMember::updateOrCreate(
                ['organization_id' => $acme->id, 'user_id' => $user->id],
                ['role' => Role::from($role)],
            );
        }

        // A second tenant to exercise isolation by hand.
        $outsider = User::updateOrCreate(
            ['email' => 'outsider@example.com'],
            ['name' => 'Outsider User', 'password' => $password, 'email_verified_at' => now()],
        );
        $globex = Organization::updateOrCreate(['slug' => 'globex'], ['name' => 'Globex']);
        OrganizationMember::updateOrCreate(
            ['organization_id' => $globex->id, 'user_id' => $outsider->id],
            ['role' => Role::Owner],
        );

        $tenant->set($acme, $acme->members()->where('user_id', $users['owner']->id)->firstOrFail());

        try {
            if (! $acme->projects()->exists()) {
                $this->seedSigWebsite($registrar, $secrets, $users['owner']);
                $this->seedAssetTracker($registrar, $users['admin']);
            }
        } finally {
            $tenant->clear();
        }

        $tenant->set($globex, $globex->members()->where('user_id', $outsider->id)->firstOrFail());

        try {
            if (! $globex->projects()->exists()) {
                $registrar->create([
                    'name' => 'Customer App',
                    'description' => 'Customer-facing mobile backend.',
                    'repository_url' => 'https://github.com/globex/customer-app',
                    'framework' => 'Express',
                    'language' => 'TypeScript',
                    'database_type' => 'postgresql',
                    'deployment_type' => 'docker',
                    'status' => ProjectStatus::Active->value,
                ], $outsider);
            }
        } finally {
            $tenant->clear();
        }
    }

    private function seedSigWebsite(ProjectRegistrar $registrar, SecretStore $secrets, User $owner): void
    {
        $project = $registrar->create([
            'name' => 'SIG Website',
            'description' => 'Public website and product catalogue API.',
            'repository_url' => 'https://github.com/acme/sig-website',
            'default_branch' => 'main',
            'framework' => 'Laravel + React (Vite)',
            'language' => 'PHP / TypeScript',
            'database_type' => 'mysql',
            'deployment_type' => 'docker',
            'status' => ProjectStatus::Active->value,
            'ai_context' => [
                'notes' => 'Do not modify the production database directly. Deployments go through Hostinger VPS via Docker.',
            ],
        ], $owner);

        $environments = $project->environments()->get()->keyBy(fn ($e) => $e->type->value);
        $production = $environments[EnvironmentType::Production->value];
        $staging = $environments[EnvironmentType::Staging->value];

        $production->update(['url' => 'https://sig.example.com', 'health_check_url' => 'https://sig.example.com/up']);
        $staging->update(['url' => 'https://staging.sig.example.com', 'branch' => 'develop', 'health_check_url' => 'https://staging.sig.example.com/up']);

        $server = $project->servers()->make([
            'environment_id' => $production->id,
            'name' => 'sig-prod-01',
            'hostname' => 'sig-prod-01.example.com',
            'provider' => 'Hostinger VPS',
            'os' => 'Ubuntu 24.04',
            'connection_type' => ServerConnectionType::None,
            'status' => ResourceStatus::Unknown,
        ]);
        $server->organization_id = $project->organization_id;
        $server->save();

        $database = $project->databases()->make([
            'environment_id' => $production->id,
            'server_id' => $server->id,
            'name' => 'sig-mysql',
            'engine' => DatabaseEngine::MySQL,
            'version' => '8.0',
            'host' => 'db.internal',
            'port' => 3306,
            'database_name' => 'sig',
            'status' => ResourceStatus::Unknown,
        ]);
        $database->organization_id = $project->organization_id;
        $database->save();

        foreach ([
            ['name' => 'sig-backend', 'type' => ServiceType::Api, 'container_name' => 'sig-backend', 'port' => 8000],
            ['name' => 'sig-frontend', 'type' => ServiceType::Web, 'container_name' => 'sig-frontend', 'port' => 80],
            ['name' => 'sig-queue', 'type' => ServiceType::Worker, 'container_name' => 'sig-queue', 'port' => null],
        ] as $service) {
            $model = $project->services()->make([
                ...$service,
                'environment_id' => $production->id,
                'server_id' => $server->id,
                'runtime' => ServiceRuntime::Docker,
                'status' => ResourceStatus::Unknown,
            ]);
            $model->organization_id = $project->organization_id;
            $model->save();
        }

        $secrets->put($staging, 'APP_ENV', 'staging', false, $owner);
        $secrets->put($staging, 'DB_PASSWORD', bin2hex(random_bytes(12)), true, $owner);
        $secrets->put($production, 'APP_ENV', 'production', false, $owner);
        $secrets->put($production, 'DB_PASSWORD', bin2hex(random_bytes(12)), true, $owner);
    }

    private function seedAssetTracker(ProjectRegistrar $registrar, User $admin): void
    {
        $registrar->create([
            'name' => 'Asset Tracker',
            'description' => 'Internal asset inventory and tracking service.',
            'repository_url' => 'https://github.com/acme/asset-tracker',
            'framework' => 'FastAPI',
            'language' => 'Python',
            'database_type' => 'postgresql',
            'deployment_type' => 'docker',
            'status' => ProjectStatus::Onboarding->value,
        ], $admin);
    }
}
