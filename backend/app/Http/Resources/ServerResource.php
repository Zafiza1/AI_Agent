<?php

namespace App\Http\Resources;

use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Server */
class ServerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'environment_id' => $this->environment_id,
            'name' => $this->name,
            'hostname' => $this->hostname,
            'ip_address' => $this->ip_address,
            'provider' => $this->provider,
            'os' => $this->os,
            'connection_type' => $this->connection_type->value,
            'status' => $this->status->value,
            'metadata' => $this->metadata ?? (object) [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
