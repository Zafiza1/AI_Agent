<?php

namespace App\Http\Resources;

use App\Contracts\SecretStore;
use App\Models\EnvironmentVariable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Secret values are write-only: they are never decrypted for a response.
 *
 * @mixin EnvironmentVariable
 */
class EnvironmentVariableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'environment_id' => $this->environment_id,
            'key' => $this->key,
            'is_secret' => $this->is_secret,
            'value' => $this->is_secret ? null : app(SecretStore::class)->reveal($this->resource),
            'updated_at' => $this->updated_at,
        ];
    }
}
