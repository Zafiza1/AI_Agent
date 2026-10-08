<?php

namespace App\Services\Git\Data;

use Carbon\CarbonImmutable;

final readonly class RemoteCommit
{
    public function __construct(
        public string $sha,
        public string $message,
        public ?string $authorName,
        public ?string $authorLogin,
        public ?CarbonImmutable $committedAt,
        public ?string $url,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sha' => $this->sha,
            'short_sha' => substr($this->sha, 0, 7),
            'message' => $this->message,
            'author_name' => $this->authorName,
            'author_login' => $this->authorLogin,
            'committed_at' => $this->committedAt?->toIso8601String(),
            'url' => $this->url,
        ];
    }
}
