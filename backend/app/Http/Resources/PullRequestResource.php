<?php

namespace App\Http\Resources;

use App\Models\PullRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PullRequest */
class PullRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'repository_id' => $this->repository_id,
            'provider' => $this->provider->value,
            'number' => $this->number,
            'title' => $this->title,
            'state' => $this->state->value,
            'is_draft' => $this->is_draft,
            'head_branch' => $this->head_branch,
            'head_sha' => $this->head_sha,
            'base_branch' => $this->base_branch,
            'url' => $this->url,
            'author_login' => $this->author_login,
            'checks_status' => $this->checks_status?->value,
            'opened_via_platform' => $this->opened_via_platform,
            'opened_by' => new UserResource($this->whenLoaded('opener')),
            'repository' => $this->whenLoaded('repository', fn () => [
                'id' => $this->repository->id,
                'full_name' => $this->repository->full_name,
            ]),
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ]),
            'opened_at' => $this->opened_at,
            'merged_at' => $this->merged_at,
            'closed_at' => $this->closed_at,
            'synced_at' => $this->synced_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
