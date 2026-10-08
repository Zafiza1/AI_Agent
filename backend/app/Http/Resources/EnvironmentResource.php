<?php

namespace App\Http\Resources;

use App\Models\Environment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Environment */
class EnvironmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'type' => $this->type->value,
            'url' => $this->url,
            'branch' => $this->branch,
            'health_check_url' => $this->health_check_url,
            'is_protected' => $this->is_protected,
            'requires_approval' => $this->requires_approval,
            'deployment_config' => $this->deployment_config ?? (object) [],
            'variables_count' => $this->whenCounted('variables'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
