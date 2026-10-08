<?php

namespace App\Http\Resources;

use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Repository */
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
            'connection_status' => $this->connection_status->value,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
