<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'actor_type' => $this->actor_type->value,
            'user' => new UserResource($this->whenLoaded('user')),
            'agent_id' => $this->agent_id,
            'action' => $this->action,
            'tool' => $this->tool,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'risk_level' => $this->risk_level?->value,
            'approval_id' => $this->approval_id,
            'result' => $this->result->value,
            'metadata' => $this->metadata ?? (object) [],
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at,
        ];
    }
}
