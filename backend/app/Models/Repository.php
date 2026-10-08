<?php

namespace App\Models;

use App\Enums\RepositoryConnectionStatus;
use App\Enums\RepositoryProvider;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A source repository registered for a project. The provider connection
 * (GitHub App installation, webhooks) is established in Phase 2.
 */
#[Fillable(['provider', 'url', 'full_name', 'default_branch', 'is_primary', 'connection_status', 'external_id'])]
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
    ];

    protected function casts(): array
    {
        return [
            'provider' => RepositoryProvider::class,
            'connection_status' => RepositoryConnectionStatus::class,
            'is_primary' => 'boolean',
        ];
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
}
