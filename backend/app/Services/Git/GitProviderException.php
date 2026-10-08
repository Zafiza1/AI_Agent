<?php

namespace App\Services\Git;

use RuntimeException;
use Throwable;

/**
 * A failed call to a git provider. The message is safe to show to users: it never
 * contains credentials. `remoteStatus` is the provider's HTTP status (0 for network errors).
 */
class GitProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly int $remoteStatus = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function isNotFound(): bool
    {
        return $this->remoteStatus === 404;
    }

    public function isUnauthorized(): bool
    {
        return $this->remoteStatus === 401;
    }

    /**
     * Status for the platform's own response: the caller's input was wrong (422),
     * or the provider failed / is unreachable (502).
     */
    public function httpStatus(): int
    {
        return $this->remoteStatus >= 400 && $this->remoteStatus < 500 ? 422 : 502;
    }
}
