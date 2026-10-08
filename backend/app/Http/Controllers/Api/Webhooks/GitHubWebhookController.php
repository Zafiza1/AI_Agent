<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Enums\RepositoryProvider;
use App\Enums\WebhookDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessGitHubWebhook;
use App\Models\GitConnection;
use App\Models\Repository;
use App\Models\WebhookDelivery;
use App\Services\Git\GitHub\GitHubAppAuth;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public GitHub webhook receiver. Nothing in a delivery is trusted until its
 * HMAC-SHA256 signature verifies against the right secret:
 *
 *  - POST /api/webhooks/github                         GitHub App deliveries (App webhook secret)
 *  - POST /api/webhooks/github/repositories/{id}       repository webhooks (per-repository secret)
 *
 * Verified deliveries are stored once (idempotent on X-GitHub-Delivery) and processed on the queue.
 */
class GitHubWebhookController extends Controller
{
    private const MAX_PAYLOAD_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly GitHubAppAuth $githubApp) {}

    public function app(Request $request): JsonResponse
    {
        $secret = $this->githubApp->webhookSecret();

        if (! filled($secret)) {
            return response()->json(['message' => 'GitHub App webhooks are not configured.'], Response::HTTP_NOT_FOUND);
        }

        if ($rejected = $this->reject($request, $secret)) {
            return $rejected;
        }

        $installationId = (int) $request->json('installation.id');
        $connection = $installationId > 0
            ? GitConnection::query()->where('installation_id', $installationId)->first()
            : null;

        return $this->accept($request, $connection, null);
    }

    public function repository(Request $request, string $repository): JsonResponse
    {
        $model = Repository::query()->whereKey($repository)->first();
        $secret = $model?->webhook_secret;

        // Same response for unknown repositories and repositories without a hook.
        if (! filled($secret)) {
            return response()->json(['message' => 'Unknown webhook.'], Response::HTTP_NOT_FOUND);
        }

        if ($rejected = $this->reject($request, $secret)) {
            return $rejected;
        }

        return $this->accept($request, $model->gitConnection, $model);
    }

    private function reject(Request $request, string $secret): ?JsonResponse
    {
        $body = $request->getContent();

        if (strlen($body) > self::MAX_PAYLOAD_BYTES) {
            return response()->json(['message' => 'Payload too large.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $body, $secret);

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], Response::HTTP_UNAUTHORIZED);
        }

        return null;
    }

    private function accept(Request $request, ?GitConnection $connection, ?Repository $repository): JsonResponse
    {
        $event = (string) $request->header('X-GitHub-Event', '');
        $deliveryId = (string) $request->header('X-GitHub-Delivery', '');

        if (! preg_match('/^[a-z_]{1,64}$/', $event) || ! preg_match('/^[A-Za-z0-9-]{1,100}$/', $deliveryId)) {
            return response()->json(['message' => 'Missing or invalid GitHub delivery headers.'], Response::HTTP_BAD_REQUEST);
        }

        if ($event === 'ping') {
            return response()->json(['message' => 'pong']);
        }

        $payload = $request->json()->all();
        $duplicate = response()->json(['message' => 'Delivery already received.']);

        // GitHub redelivers on timeouts; the delivery id is the idempotency key.
        if (WebhookDelivery::query()->where('provider', RepositoryProvider::GitHub->value)->where('delivery_id', $deliveryId)->exists()) {
            return $duplicate;
        }

        try {
            // A savepoint, so a concurrent duplicate cannot abort an outer PostgreSQL transaction.
            $delivery = DB::transaction(fn () => WebhookDelivery::create([
                'organization_id' => $repository?->organization_id ?? $connection?->organization_id,
                'git_connection_id' => $connection?->id,
                'repository_id' => $repository?->id,
                'external_repository_id' => isset($payload['repository']['id']) ? (string) $payload['repository']['id'] : null,
                'provider' => RepositoryProvider::GitHub,
                'delivery_id' => $deliveryId,
                'event' => $event,
                'action' => is_string($payload['action'] ?? null) ? mb_substr($payload['action'], 0, 64) : null,
                // Deliveries for unknown installations are kept for debugging but not processed.
                'status' => $connection || $repository ? WebhookDeliveryStatus::Received : WebhookDeliveryStatus::Ignored,
                'payload' => $payload,
                'received_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return $duplicate;
        }

        if ($delivery->status === WebhookDeliveryStatus::Received) {
            ProcessGitHubWebhook::dispatch($delivery->id);
        }

        return response()->json(['message' => 'Accepted.', 'delivery' => $delivery->id], Response::HTTP_ACCEPTED);
    }
}
