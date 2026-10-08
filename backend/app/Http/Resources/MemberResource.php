<?php

namespace App\Http\Resources;

use App\Models\OrganizationMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrganizationMember */
class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'user' => new UserResource($this->whenLoaded('user')),
            'joined_at' => $this->created_at,
        ];
    }
}
