<?php

namespace App\Enums;

enum RepositoryConnectionStatus: string
{
    case NotConnected = 'not_connected';
    case Connected = 'connected';
    case Error = 'error';
}
