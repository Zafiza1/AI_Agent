<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A normalized, verified event from GitHub, e.g. `github.issue.created`,
 * `github.pull_request.opened`, `github.pull_request.failed` or `ci.build.failed`.
 *
 * Phase 3 (agent tasks) and Phase 7 (event bus) subscribe to this to create work.
 * `data` is a small summary taken from the payload; it never contains credentials.
 */
class GitHubEventReceived
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $name,
        public readonly string $organizationId,
        public readonly string $projectId,
        public readonly string $repositoryId,
        public readonly string $deliveryId,
        public readonly array $data = [],
    ) {}
}
