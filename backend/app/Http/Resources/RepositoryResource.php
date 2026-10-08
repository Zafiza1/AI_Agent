<?php

namespace App\Http\Resources;

use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never includes the webhook secret.
 *
 * @mixin Repository
 */
class RepositoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'provider' => $this->provider->value,
            'url' => $this->url,
            'full_name' => $this->full_name,
            'default_branch' => $this->default_branch,
            'is_primary' => $this->is_primary,
            'is_private' => $this->is_private,
            'connection_status' => $this->connection_status->value,
            'connection_error' => $this->connection_error,
            'git_connection_id' => $this->git_connection_id,
            'git_connection' => $this->whenLoaded('gitConnection', fn () => $this->gitConnection ? [
                'id' => $this->gitConnection->id,
                'name' => $this->gitConnection->name,
                'auth_type' => $this->gitConnection->auth_type->value,
                'status' => $this->gitConnection->status->value,
            ] : null),
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ]),
            'webhook_status' => $this->webhook_status->value,
            'webhook_error' => $this->webhook_error,
            'last_commit_sha' => $this->last_commit_sha,
            'last_pushed_at' => $this->last_pushed_at,
            'last_synced_at' => $this->last_synced_at,
            'open_pull_requests_count' => $this->whenCounted('openPullRequests'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
