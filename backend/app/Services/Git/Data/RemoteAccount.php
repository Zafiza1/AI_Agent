<?php

namespace App\Services\Git\Data;

final readonly class RemoteAccount
{
    /**
     * @param  array<array-key, string>  $scopes  token scopes, or app permissions keyed by name
     */
    public function __construct(
        public string $login,
        public string $type,
        public array $scopes = [],
        public bool $suspended = false,
    ) {}
}
