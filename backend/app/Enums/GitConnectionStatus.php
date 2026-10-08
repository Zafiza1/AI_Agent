<?php

namespace App\Enums;

enum GitConnectionStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Error = 'error';
    case Revoked = 'revoked';
}
