<?php

namespace App\Enums;

enum ServiceRuntime: string
{
    case Docker = 'docker';
    case Systemd = 'systemd';
    case Pm2 = 'pm2';
    case Serverless = 'serverless';
    case Managed = 'managed';
    case Other = 'other';
}
