<?php

namespace App\Services\Git\Data;

final readonly class RemoteBranch
{
    public function __construct(
        public string $name,
        public string $sha,
        public bool $protected = false,
    ) {}
}
