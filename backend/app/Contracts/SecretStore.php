<?php

namespace App\Contracts;

use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\User;

/**
 * Storage boundary for environment configuration and secrets.
 *
 * The default implementation encrypts values in PostgreSQL with the application key.
 * A Vault / cloud KMS implementation can be bound in AppServiceProvider without
 * touching callers. Plaintext must only ever leave the store through reveal(), and
 * reveal() must never be used to build API responses for secrets or LLM prompts.
 */
interface SecretStore
{
    public function put(Environment $environment, string $key, string $value, bool $isSecret, ?User $actor = null): EnvironmentVariable;

    public function reveal(EnvironmentVariable $variable): string;

    public function forget(EnvironmentVariable $variable): void;
}
