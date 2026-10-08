<?php

namespace App\Http\Resources;

use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Delivery metadata only; the raw payload is not exposed.
 *
 * @mixin WebhookDelivery
 */
class WebhookDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'delivery_id' => $this->delivery_id,
            'event' => $this->event,
            'action' => $this->action,
            'status' => $this->status->value,
            'error' => $this->error,
            'received_at' => $this->received_at,
            'processed_at' => $this->processed_at,
        ];
    }
}
