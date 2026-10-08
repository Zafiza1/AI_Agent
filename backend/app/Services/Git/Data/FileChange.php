<?php

namespace App\Services\Git\Data;

/**
 * One file in a commit: new contents, or a deletion when $content is null.
 */
final readonly class FileChange
{
    public function __construct(
        public string $path,
        public ?string $content,
    ) {}

    public function isDeletion(): bool
    {
        return $this->content === null;
    }
}
