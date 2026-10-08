<?php

namespace App\Enums;

enum WebhookDeliveryStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
