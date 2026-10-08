<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Onboarding = 'onboarding';
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';
}
