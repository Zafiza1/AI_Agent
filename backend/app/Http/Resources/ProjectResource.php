<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Project */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $primary = $this->resource->relationLoaded('primaryRepository') ? $this->primaryRepository : null;

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'repository_url' => $primary?->url,
            'repository_provider' => $primary?->provider->value,
            'default_branch' => $primary?->default_branch,
            'framework' => $this->framework,
            'language' => $this->language,
            'database_type' => $this->database_type,
            'deployment_type' => $this->deployment_type,
            'status' => $this->status->value,
            'ai_context' => $this->ai_context ?? (object) [],
            'primary_repository' => new RepositoryResource($this->whenLoaded('primaryRepository')),
            'environments' => EnvironmentResource::collection($this->whenLoaded('environments')),
            'counts' => [
                'environments' => $this->whenCounted('environments'),
                'repositories' => $this->whenCounted('repositories'),
                'servers' => $this->whenCounted('servers'),
                'databases' => $this->whenCounted('databases'),
                'services' => $this->whenCounted('services'),
            ],
            'created_by' => new UserResource($this->whenLoaded('creator')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
