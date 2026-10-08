<?php

namespace App\Enums;

enum ServiceType: string
{
    case Web = 'web';
    case Api = 'api';
    case Worker = 'worker';
    case Scheduler = 'scheduler';
    case Database = 'database';
    case Cache = 'cache';
    case Queue = 'queue';
    case Other = 'other';
}
