<?php

namespace App\Services\Git\GitHub;

use App\Services\Git\GitProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the GitHub REST API for one credential. It maps every failure
 * to a GitProviderException whose message is safe to show (no tokens, no headers).
 */
class GitHubClient
{
    private const API_VERSION = '2022-11-28';

    private const MAX_PAGE_SIZE = 100;

    public function __construct(private readonly string $token) {}

    public static function apiUrl(): string
    {
        return config('services.github.api_url');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query)->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function post(string $path, array $data = []): array
    {
        return $this->send('POST', $path, $data)->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function patch(string $path, array $data = []): array
    {
        return $this->send('PATCH', $path, $data)->json() ?? [];
    }

    public function delete(string $path): void
    {
        $this->send('DELETE', $path);
    }

    /**
     * Follow page-numbered results until $limit items or the last page.
     *
     * @param  array<string, mixed>  $query
     * @param  string|null  $itemsKey  key holding the items when the response is an object
     * @return list<array<string, mixed>>
     */
    public function paginate(string $path, array $query = [], int $limit = 100, ?string $itemsKey = null): array
    {
        $items = [];
        $perPage = min(self::MAX_PAGE_SIZE, max(1, $limit));

        for ($page = 1; count($items) < $limit; $page++) {
            $response = $this->get($path, [...$query, 'per_page' => $perPage, 'page' => $page]);
            $batch = $itemsKey === null ? $response : ($response[$itemsKey] ?? []);

            array_push($items, ...array_values($batch));

            if (count($batch) < $perPage) {
                break;
            }
        }

        return array_slice($items, 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws GitProviderException
     */
    public function send(string $method, string $path, array $data = []): Response
    {
        try {
            $response = $this->request()->send($method, ltrim($path, '/'), match (true) {
                $data === [] => [],
                $method === 'GET' => ['query' => $data],
                default => ['json' => $data],
            });
        } catch (ConnectionException $e) {
            throw new GitProviderException('GitHub could not be reached. Try again later.', 0, $e);
        }

        if ($response->failed()) {
            throw self::exceptionFor($response);
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::apiUrl())
            ->withToken($this->token)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => self::API_VERSION])
            ->withUserAgent(config('app.name', 'AI-Maintenance-Platform'))
            ->timeout(config('services.github.timeout', 15))
            ->connectTimeout(5);
    }

    public static function exceptionFor(Response $response): GitProviderException
    {
        $status = $response->status();
        $message = (string) ($response->json('message') ?? '');
        $detail = $response->json('errors.0.message') ?? $response->json('errors.0.code');
        $remote = trim($message.($detail ? ": {$detail}" : ''));

        $text = match (true) {
            $status === 401 => 'GitHub rejected the credentials. They may be expired or revoked.',
            $status === 403 && $response->header('X-RateLimit-Remaining') === '0' => 'GitHub API rate limit exceeded. Try again later.',
            $status === 403 => 'GitHub denied access'.($remote !== '' ? ": {$remote}" : '.'),
            $status === 404 => 'Not found on GitHub, or not accessible with this connection.',
            $status === 409, $status === 422 => 'GitHub rejected the request'.($remote !== '' ? ": {$remote}" : '.'),
            default => "GitHub request failed with status {$status}.",
        };

        return new GitProviderException($text, $status);
    }
}
