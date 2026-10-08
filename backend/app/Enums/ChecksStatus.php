<?php

namespace App\Enums;

/**
 * Aggregated CI status of a pull request's head commit.
 */
enum ChecksStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failure = 'failure';
    case Neutral = 'neutral';

    /**
     * Map a GitHub check suite / workflow run conclusion to a status.
     */
    public static function fromConclusion(?string $conclusion): self
    {
        return match ($conclusion) {
            null => self::Pending,
            'success' => self::Success,
            'failure', 'timed_out', 'startup_failure', 'action_required' => self::Failure,
            default => self::Neutral,
        };
    }
}
