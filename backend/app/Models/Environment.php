<?php

namespace App\Models;

use App\Enums\EnvironmentType;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Rbac\Permission;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'type', 'url', 'branch', 'health_check_url',
    'is_protected', 'requires_approval', 'deployment_config',
])]
class Environment extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * Mirrors the column defaults so new instances are complete before a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_protected' => false,
        'requires_approval' => false,
    ];

    protected function casts(): array
    {
        return [
            'type' => EnvironmentType::class,
            'is_protected' => 'boolean',
            'requires_approval' => 'boolean',
            'deployment_config' => 'array',
        ];
    }

    /**
     * The permission required to change this environment or anything attached to it.
     */
    public function managePermission(): Permission
    {
        return $this->is_protected
            ? Permission::EnvironmentsManageProtected
            : Permission::EnvironmentsManage;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<EnvironmentVariable, $this>
     */
    public function variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class);
    }
}
