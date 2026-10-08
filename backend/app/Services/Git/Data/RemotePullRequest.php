<?php

namespace App\Services\Git\Data;

use App\Enums\PullRequestState;
use Carbon\CarbonImmutable;

final readonly class RemotePullRequest
{
    public function __construct(
        public string $id,
        public int $number,
        public string $title,
        public PullRequestState $state,
        public bool $draft,
        public string $headBranch,
        public ?string $headSha,
        public string $baseBranch,
        public ?string $url,
        public ?string $authorLogin,
        public ?CarbonImmutable $openedAt,
        public ?CarbonImmutable $mergedAt,
        public ?CarbonImmutable $closedAt,
    ) {}
}
