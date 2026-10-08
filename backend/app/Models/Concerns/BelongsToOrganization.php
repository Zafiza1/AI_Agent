<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Tenant isolation for models that carry an organization_id.
 *
 * - While an organization is resolved for the request, every query is constrained
 *   to it, so IDs belonging to other tenants resolve to "not found".
 * - On create, organization_id is always taken from the resolved organization and
 *   never from user input.
 *
 * @mixin Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $query) {
            $tenant = app(CurrentOrganization::class);

            if ($tenant->has()) {
                $query->where($query->qualifyColumn('organization_id'), $tenant->id());
            }
        });

        static::creating(function (Model $model) {
            $tenant = app(CurrentOrganization::class);

            if ($tenant->has()) {
                $model->setAttribute('organization_id', $tenant->id());
            } elseif (! $model->getAttribute('organization_id')) {
                throw new LogicException(static::class.' requires an organization_id.');
            }
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('organization_id')) {
                throw new LogicException('Moving a resource between organizations is not allowed.');
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
