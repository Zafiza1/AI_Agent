<?php

namespace App\Http\Resources;

use App\Models\DatabaseInstance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DatabaseInstance */
class DatabaseInstanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'environment_id' => $this->environment_id,
            'server_id' => $this->server_id,
            'name' => $this->name,
            'engine' => $this->engine->value,
            'version' => $this->version,
            'host' => $this->host,
            'port' => $this->port,
            'database_name' => $this->database_name,
            'status' => $this->status->value,
            'metadata' => $this->metadata ?? (object) [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
