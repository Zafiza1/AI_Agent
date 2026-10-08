<?php

namespace App\Services\Projects;

use App\Enums\EnvironmentType;
use App\Enums\ProjectStatus;
use App\Enums\RepositoryProvider;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;

/**
 * Creates and updates projects together with their primary repository and
 * default environments, keeping those writes in one transaction.
 */
class ProjectRegistrar
{
    /**
     * @param  array<string, mixed>  $data  validated project input
     */
    public function create(array $data, User $creator): Project
    {
        return DB::transaction(function () use ($data, $creator) {
            $project = new Project([
                ...array_intersect_key($data, array_flip([
                    'name', 'description', 'framework', 'language',
                    'database_type', 'deployment_type', 'ai_context',
                ])),
                'slug' => Slug::unique($data['name'], fn (string $slug) => Project::withTrashed()->where('slug', $slug)->exists()),
                'status' => $data['status'] ?? ProjectStatus::Onboarding->value,
            ]);
            $project->created_by = $creator->id;
            $project->save();

            if (! empty($data['repository_url'])) {
                $this->syncPrimaryRepository($project, $data);
            }

            if ($data['create_default_environments'] ?? true) {
                foreach (EnvironmentType::cases() as $type) {
                    $this->createEnvironment($project, $type->value, $type, [
                        'branch' => $type === EnvironmentType::Production ? ($data['default_branch'] ?? 'main') : null,
                    ]);
                }
            }

            return $project;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated project input
     */
    public function update(Project $project, array $data): Project
    {
        return DB::transaction(function () use ($project, $data) {
            $project->fill(array_intersect_key($data, array_flip([
                'name', 'description', 'framework', 'language',
                'database_type', 'deployment_type', 'status', 'ai_context',
            ])))->save();

            if (array_key_exists('repository_url', $data) || array_key_exists('default_branch', $data)) {
                $this->syncPrimaryRepository($project, $data);
            }

            return $project;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createEnvironment(Project $project, string $name, EnvironmentType $type, array $attributes = []): Environment
    {
        $environment = new Environment([
            'name' => $name,
            'type' => $type,
            'is_protected' => $type->isProtectedByDefault(),
            'requires_approval' => $type->isProtectedByDefault(),
            ...array_filter($attributes, fn ($value) => $value !== null),
        ]);
        $environment->project()->associate($project);
        $environment->organization_id = $project->organization_id;
        $environment->save();

        return $environment;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncPrimaryRepository(Project $project, array $data): void
    {
        $primary = $project->primaryRepository()->first();
        $url = $data['repository_url'] ?? $primary?->url;

        if (! $url) {
            return;
        }

        $primary ??= new Repository(['is_primary' => true]);
        $primary->fill([
            'url' => $url,
            'provider' => $data['repository_provider'] ?? RepositoryProvider::fromUrl($url)->value,
            'full_name' => Repository::fullNameFromUrl($url),
            'default_branch' => $data['default_branch'] ?? $primary->default_branch ?? 'main',
        ]);
        $primary->project()->associate($project);
        $primary->organization_id = $project->organization_id;
        $primary->save();
    }
}
