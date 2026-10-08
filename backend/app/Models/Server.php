<?php

namespace App\Models;

use App\Enums\ResourceStatus;
use App\Enums\ServerConnectionType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['environment_id', 'name', 'hostname', 'ip_address', 'provider', 'os', 'connection_type', 'status', 'metadata'])]
class Server extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * Mirrors the column defaults so new instances are complete before a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'connection_type' => 'none',
        'status' => 'unknown',
    ];

    protected function casts(): array
    {
        return [
            'connection_type' => ServerConnectionType::class,
            'status' => ResourceStatus::class,
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
