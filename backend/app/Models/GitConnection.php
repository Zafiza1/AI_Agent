<?php

namespace App\Models;

use App\Enums\GitConnectionAuthType;
use App\Enums\GitConnectionStatus;
use App\Enums\RepositoryProvider;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organization's authorization to a git provider: a GitHub App installation or
 * a personal access token. `credentials` is encrypted at rest, hidden from
 * serialization and must never be returned by the API, logged or sent to an LLM.
 */
#[Fillable([
    'provider', 'auth_type', 'name', 'account_login', 'account_type', 'installation_id',
    'scopes', 'status', 'last_error', 'last_verified_at',
])]
#[Hidden(['credentials'])]
class GitConnection extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'provider' => RepositoryProvider::class,
            'auth_type' => GitConnectionAuthType::class,
            'status' => GitConnectionStatus::class,
            'installation_id' => 'integer',
            'credentials' => 'encrypted',
            'scopes' => 'array',
            'last_verified_at' => 'datetime',
        ];
    }

    public function isApp(): bool
    {
        return $this->auth_type === GitConnectionAuthType::GitHubApp;
    }

    /**
     * @return HasMany<Repository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
