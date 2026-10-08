<?php

namespace App\Enums;

/** Health status of servers, databases and services as last observed. */
enum ResourceStatus: string
{
    case Unknown = 'unknown';
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Critical = 'critical';
    case Offline = 'offline';
}
