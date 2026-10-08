<?php

namespace App\Http\Resources;

use App\Models\GitConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never includes credentials.
 *
 * @mixin GitConnection
 */
class GitConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider->value,
            'auth_type' => $this->auth_type->value,
            'name' => $this->name,
            'account_login' => $this->account_login,
            'account_type' => $this->account_type,
            'installation_id' => $this->installation_id,
            'scopes' => $this->scopes ?? [],
            'status' => $this->status->value,
            'last_error' => $this->last_error,
            'last_verified_at' => $this->last_verified_at,
            'repositories_count' => $this->whenCounted('repositories'),
            'created_by' => new UserResource($this->whenLoaded('creator')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
