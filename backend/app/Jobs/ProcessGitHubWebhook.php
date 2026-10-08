<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Git\GitHub\GitHubWebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Processes a stored, signature-verified GitHub delivery off the request path.
 */
class ProcessGitHubWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $deliveryId) {}

    public function handle(GitHubWebhookProcessor $processor): void
    {
        $delivery = WebhookDelivery::find($this->deliveryId);

        if ($delivery) {
            $processor->process($delivery);
        }
    }
}
