<?php

namespace App\Models;

use App\Enums\DatabaseEngine;
use App\Enums\ResourceStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A database used by a project. Credentials are never stored here; they belong
 * in environment secrets.
 */
#[Table('databases')]
#[Fillable(['environment_id', 'server_id', 'name', 'engine', 'version', 'host', 'port', 'database_name', 'status', 'metadata'])]
class DatabaseInstance extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * Mirrors the column defaults so new instances are complete before a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'unknown',
    ];

    protected function casts(): array
    {
        return [
            'engine' => DatabaseEngine::class,
            'status' => ResourceStatus::class,
            'port' => 'integer',
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

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
