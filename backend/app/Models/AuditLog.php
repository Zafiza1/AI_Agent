<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Enums\AuditResult;
use App\Enums\RiskLevel;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit record. Write it through App\Services\Audit\AuditLogger.
 */
#[Fillable([
    'organization_id', 'project_id', 'user_id', 'actor_type', 'agent_id', 'action', 'tool',
    'target_type', 'target_id', 'risk_level', 'approval_id', 'result', 'metadata',
    'ip_address', 'user_agent', 'created_at',
])]
class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        // Audit logs can be written without a tenant (e.g. failed logins), so they
        // use a read-only scope instead of BelongsToOrganization.
        static::addGlobalScope('organization', function (Builder $query) {
            $tenant = app(CurrentOrganization::class);

            if ($tenant->has()) {
                $query->where('organization_id', $tenant->id());
            }
        });

        static::updating(fn () => throw new LogicException('Audit logs are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit logs are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'result' => AuditResult::class,
            'risk_level' => RiskLevel::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
