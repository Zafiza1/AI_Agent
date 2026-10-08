<?php

namespace App\Enums;

/**
 * How a repository receives provider webhooks.
 */
enum WebhookStatus: string
{
    case NotConfigured = 'not_configured';
    /** Delivered through the GitHub App's app-level webhook. */
    case Managed = 'managed';
    /** A repository webhook created by the platform with its own secret. */
    case Active = 'active';
    case Failed = 'failed';
}
