<?php

namespace App\Enums;

enum ServerConnectionType: string
{
    case None = 'none';
    case Ssh = 'ssh';
    case Agent = 'agent';
}
