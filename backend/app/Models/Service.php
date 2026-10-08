<?php

namespace App\Models;

use App\Enums\ResourceStatus;
use App\Enums\ServiceRuntime;
use App\Enums\ServiceType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['environment_id', 'server_id', 'name', 'type', 'runtime', 'container_name', 'port', 'health_check_url', 'status', 'metadata'])]
class Service extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * Mirrors the column defaults so new instances are complete before a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'runtime' => 'docker',
        'status' => 'unknown',
    ];

    protected function casts(): array
    {
        return [
            'type' => ServiceType::class,
            'runtime' => ServiceRuntime::class,
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
