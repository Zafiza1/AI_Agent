<?php

namespace App\Models;

use App\Enums\RepositoryProvider;
use App\Enums\WebhookDeliveryStatus;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An inbound provider webhook. Stored before processing so deliveries are
 * idempotent (unique delivery id) and failures can be inspected and retried.
 */
#[Fillable([
    'organization_id', 'git_connection_id', 'repository_id', 'external_repository_id', 'provider', 'delivery_id', 'event',
    'action', 'status', 'payload', 'error', 'received_at', 'processed_at',
])]
#[Hidden(['payload'])]
class WebhookDelivery extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected static function booted(): void
    {
        // Deliveries arrive without a tenant; like audit logs they use a read-only scope.
        static::addGlobalScope('organization', function (Builder $query) {
            $tenant = app(CurrentOrganization::class);

            if ($tenant->has()) {
                $query->where('organization_id', $tenant->id());
            }
        });
    }

    protected function casts(): array
    {
        return [
            'provider' => RepositoryProvider::class,
            'status' => WebhookDeliveryStatus::class,
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * @return BelongsTo<GitConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(GitConnection::class, 'git_connection_id');
    }
}
