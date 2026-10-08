<?php

namespace App\Models;

use App\Enums\ChecksStatus;
use App\Enums\PullRequestState;
use App\Enums\RepositoryProvider;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Local mirror of a provider pull request, kept current by webhooks and syncs.
 */
#[Fillable([
    'provider', 'external_id', 'number', 'title', 'state', 'is_draft', 'head_branch', 'head_sha',
    'base_branch', 'url', 'author_login', 'checks_status', 'opened_at', 'merged_at', 'closed_at', 'synced_at',
])]
class PullRequest extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_draft' => false,
        'opened_via_platform' => false,
    ];

    protected function casts(): array
    {
        return [
            'provider' => RepositoryProvider::class,
            'state' => PullRequestState::class,
            'checks_status' => ChecksStatus::class,
            'number' => 'integer',
            'is_draft' => 'boolean',
            'opened_via_platform' => 'boolean',
            'opened_at' => 'datetime',
            'merged_at' => 'datetime',
            'closed_at' => 'datetime',
            'synced_at' => 'datetime',
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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
