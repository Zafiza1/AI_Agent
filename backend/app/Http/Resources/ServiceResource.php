<?php

namespace App\Http\Resources;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Service */
class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'environment_id' => $this->environment_id,
            'server_id' => $this->server_id,
            'name' => $this->name,
            'type' => $this->type->value,
            'runtime' => $this->runtime->value,
            'container_name' => $this->container_name,
            'port' => $this->port,
            'health_check_url' => $this->health_check_url,
            'status' => $this->status->value,
            'metadata' => $this->metadata ?? (object) [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
