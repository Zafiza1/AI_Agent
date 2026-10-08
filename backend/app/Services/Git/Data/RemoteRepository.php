<?php

namespace App\Services\Git\Data;

final readonly class RemoteRepository
{
    public function __construct(
        public string $id,
        public string $fullName,
        public string $url,
        public string $defaultBranch,
        public bool $private,
        public bool $archived = false,
        public bool $canAdmin = false,
        public bool $canPush = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->fullName,
            'url' => $this->url,
            'default_branch' => $this->defaultBranch,
            'private' => $this->private,
            'archived' => $this->archived,
            'can_push' => $this->canPush,
        ];
    }
}
