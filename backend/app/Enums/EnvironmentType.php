<?php

namespace App\Enums;

enum EnvironmentType: string
{
    case Development = 'development';
    case Staging = 'staging';
    case Production = 'production';

    /** Production is protected and approval-gated unless explicitly changed by an admin. */
    public function isProtectedByDefault(): bool
    {
        return $this === self::Production;
    }
}
