<?php

namespace App\Services\Git\Data;

final readonly class RemoteWebhook
{
    public function __construct(public string $id) {}
}
