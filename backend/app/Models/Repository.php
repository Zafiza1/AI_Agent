<?php

namespace App\Models;

use App\Enums\PullRequestState;
use App\Enums\RepositoryConnectionStatus;
use App\Enums\RepositoryProvider;
use App\Enums\WebhookStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A source repository registered for a project. Once linked to a GitConnection
 * the platform can read it, open branches and pull requests, and receive webhooks.
 * `webhook_secret` is encrypted at rest and never serialized.
 */
#[Fillable([
    'provider', 'url', 'full_name', 'default_branch', 'is_primary', 'is_private', 'connection_status',
    'connection_error', 'external_id', 'webhook_status', 'webhook_id', 'webhook_error',
    'last_commit_sha', 'last_pushed_at', 'last_synced_at',
])]
#[Hidden(['webhook_secret'])]
class Repository extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * Mirrors the column defaults so new instances are complete before a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'default_branch' => 'main',
        'is_primary' => false,
        'connection_status' => 'not_connected',
        'webhook_status' => 'not_configured',
    ];

    protected function casts(): array
    {
        return [
            'provider' => RepositoryProvider::class,
            'connection_status' => RepositoryConnectionStatus::class,
            'webhook_status' => WebhookStatus::class,
            'webhook_secret' => 'encrypted',
            'is_primary' => 'boolean',
            'is_private' => 'boolean',
            'last_pushed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function isConnected(): bool
    {
        return $this->git_connection_id !== null && $this->connection_status === RepositoryConnectionStatus::Connected;
    }

    /**
     * Derive "owner/name" from a repository URL such as https://github.com/acme/api.git.
     */
    public static function fullNameFromUrl(string $url): ?string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $path = preg_replace('/\.git$/', '', $path);

        return $path !== '' && substr_count($path, '/') >= 1 ? $path : null;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<GitConnection, $this>
     */
    public function gitConnection(): BelongsTo
    {
        return $this->belongsTo(GitConnection::class);
    }

    /**
     * @return HasMany<PullRequest, $this>
     */
    public function pullRequests(): HasMany
    {
        return $this->hasMany(PullRequest::class);
    }

    /**
     * @return HasMany<PullRequest, $this>
     */
    public function openPullRequests(): HasMany
    {
        return $this->pullRequests()->where('state', PullRequestState::Open->value);
    }
}
