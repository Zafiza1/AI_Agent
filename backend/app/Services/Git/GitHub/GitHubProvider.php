<?php

namespace App\Services\Git\GitHub;

use App\Contracts\Git\GitProvider;
use App\Enums\PullRequestState;
use App\Services\Git\Data\FileChange;
use App\Services\Git\Data\RemoteAccount;
use App\Services\Git\Data\RemoteBranch;
use App\Services\Git\Data\RemoteCommit;
use App\Services\Git\Data\RemotePullRequest;
use App\Services\Git\Data\RemoteRepository;
use App\Services\Git\Data\RemoteWebhook;
use App\Services\Git\GitProviderException;
use Carbon\CarbonImmutable;

/**
 * GitProvider over the GitHub REST API. Works with installation tokens (GitHub App)
 * and personal access tokens; only account verification and repository listing differ.
 */
class GitHubProvider implements GitProvider
{
    private const FULL_NAME_PATTERN = '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/';

    /**
     * @param  int|null  $installationId  set for GitHub App connections
     */
    public function __construct(
        private readonly GitHubClient $client,
        private readonly GitHubAppAuth $app,
        private readonly ?int $installationId = null,
    ) {}

    public function verify(): RemoteAccount
    {
        if ($this->installationId !== null) {
            $installation = $this->app->installation($this->installationId);

            return new RemoteAccount(
                login: (string) ($installation['account']['login'] ?? ''),
                type: strtolower((string) ($installation['account']['type'] ?? $installation['target_type'] ?? 'user')),
                scopes: array_map('strval', $installation['permissions'] ?? []),
                suspended: ! empty($installation['suspended_at']),
            );
        }

        $response = $this->client->send('GET', 'user');
        $scopes = array_values(array_filter(array_map('trim', explode(',', $response->header('X-OAuth-Scopes')))));

        return new RemoteAccount(
            login: (string) $response->json('login'),
            type: strtolower((string) ($response->json('type') ?? 'user')),
            scopes: $scopes,
        );
    }

    public function listRepositories(int $limit = 300): array
    {
        $items = $this->installationId !== null
            ? $this->client->paginate('installation/repositories', limit: $limit, itemsKey: 'repositories')
            : $this->client->paginate('user/repos', ['affiliation' => 'owner,collaborator,organization_member', 'sort' => 'updated'], $limit);

        return array_map(self::repository(...), $items);
    }

    public function getRepository(string $fullName): RemoteRepository
    {
        return self::repository($this->client->get($this->repo($fullName)));
    }

    public function listBranches(string $fullName, int $limit = 100): array
    {
        return array_map(
            fn (array $branch) => new RemoteBranch($branch['name'], $branch['commit']['sha'] ?? '', (bool) ($branch['protected'] ?? false)),
            $this->client->paginate($this->repo($fullName).'/branches', limit: $limit),
        );
    }

    public function getBranch(string $fullName, string $branch): RemoteBranch
    {
        $data = $this->client->get($this->repo($fullName).'/branches/'.self::path($branch));

        return new RemoteBranch($data['name'], $data['commit']['sha'] ?? '', (bool) ($data['protected'] ?? false));
    }

    public function createBranch(string $fullName, string $branch, string $from): RemoteBranch
    {
        $sha = $this->headSha($fullName, $from);

        $this->client->post($this->repo($fullName).'/git/refs', ['ref' => 'refs/heads/'.$branch, 'sha' => $sha]);

        return new RemoteBranch($branch, $sha);
    }

    public function listCommits(string $fullName, string $branch, int $limit = 30): array
    {
        return array_map(
            self::commit(...),
            $this->client->paginate($this->repo($fullName).'/commits', ['sha' => $branch], $limit),
        );
    }

    public function commitFiles(string $fullName, string $branch, string $message, array $changes): RemoteCommit
    {
        if ($changes === []) {
            throw new GitProviderException('A commit needs at least one file change.', 422);
        }

        $repo = $this->repo($fullName);
        $parent = $this->headSha($fullName, $branch);
        $baseTree = $this->client->get("{$repo}/git/commits/{$parent}")['tree']['sha'] ?? null;

        $tree = array_map(fn (FileChange $change) => [
            'path' => $change->path,
            'mode' => '100644',
            'type' => 'blob',
            'sha' => $change->isDeletion() ? null : $this->client->post("{$repo}/git/blobs", [
                'content' => base64_encode($change->content),
                'encoding' => 'base64',
            ])['sha'],
        ], $changes);

        $treeSha = $this->client->post("{$repo}/git/trees", ['base_tree' => $baseTree, 'tree' => $tree])['sha'];
        $commit = $this->client->post("{$repo}/git/commits", ['message' => $message, 'tree' => $treeSha, 'parents' => [$parent]]);

        // force=false: GitHub refuses the update if the branch moved meanwhile.
        $this->client->patch("{$repo}/git/refs/heads/".self::path($branch), ['sha' => $commit['sha'], 'force' => false]);

        return new RemoteCommit(
            sha: $commit['sha'],
            message: $commit['message'] ?? $message,
            authorName: $commit['author']['name'] ?? null,
            authorLogin: null,
            committedAt: isset($commit['author']['date']) ? CarbonImmutable::parse($commit['author']['date']) : null,
            url: $commit['html_url'] ?? null,
        );
    }

    public function listPullRequests(string $fullName, string $state = 'all', int $limit = 50): array
    {
        return array_map(
            self::pullRequest(...),
            $this->client->paginate($this->repo($fullName).'/pulls', ['state' => $state, 'sort' => 'updated', 'direction' => 'desc'], $limit),
        );
    }

    public function getPullRequest(string $fullName, int $number): RemotePullRequest
    {
        return self::pullRequest($this->client->get($this->repo($fullName)."/pulls/{$number}"));
    }

    public function createPullRequest(string $fullName, string $head, string $base, string $title, string $body = '', bool $draft = false): RemotePullRequest
    {
        return self::pullRequest($this->client->post($this->repo($fullName).'/pulls', [
            'head' => $head,
            'base' => $base,
            'title' => $title,
            'body' => $body,
            'draft' => $draft,
        ]));
    }

    public function createWebhook(string $fullName, string $url, string $secret, array $events): RemoteWebhook
    {
        $hook = $this->client->post($this->repo($fullName).'/hooks', [
            'name' => 'web',
            'active' => true,
            'events' => $events,
            'config' => ['url' => $url, 'content_type' => 'json', 'secret' => $secret, 'insecure_ssl' => '0'],
        ]);

        return new RemoteWebhook((string) $hook['id']);
    }

    public function deleteWebhook(string $fullName, string $webhookId): void
    {
        $this->client->delete($this->repo($fullName).'/hooks/'.rawurlencode($webhookId));
    }

    /**
     * Map a GitHub pull request object (API response or webhook payload).
     *
     * @param  array<string, mixed>  $data
     */
    public static function pullRequest(array $data): RemotePullRequest
    {
        $state = match (true) {
            ! empty($data['merged_at']) || ! empty($data['merged']) => PullRequestState::Merged,
            ($data['state'] ?? 'open') === 'closed' => PullRequestState::Closed,
            default => PullRequestState::Open,
        };

        return new RemotePullRequest(
            id: (string) ($data['id'] ?? ''),
            number: (int) $data['number'],
            title: mb_substr((string) ($data['title'] ?? ''), 0, 500),
            state: $state,
            draft: (bool) ($data['draft'] ?? false),
            headBranch: (string) ($data['head']['ref'] ?? ''),
            headSha: $data['head']['sha'] ?? null,
            baseBranch: (string) ($data['base']['ref'] ?? ''),
            url: $data['html_url'] ?? null,
            authorLogin: $data['user']['login'] ?? null,
            openedAt: self::date($data['created_at'] ?? null),
            mergedAt: self::date($data['merged_at'] ?? null),
            closedAt: self::date($data['closed_at'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function repository(array $data): RemoteRepository
    {
        return new RemoteRepository(
            id: (string) $data['id'],
            fullName: (string) $data['full_name'],
            url: (string) ($data['html_url'] ?? ''),
            defaultBranch: (string) ($data['default_branch'] ?? 'main'),
            private: (bool) ($data['private'] ?? false),
            archived: (bool) ($data['archived'] ?? false),
            canAdmin: (bool) ($data['permissions']['admin'] ?? false),
            canPush: (bool) ($data['permissions']['push'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function commit(array $data): RemoteCommit
    {
        return new RemoteCommit(
            sha: (string) $data['sha'],
            message: (string) ($data['commit']['message'] ?? ''),
            authorName: $data['commit']['author']['name'] ?? null,
            authorLogin: $data['author']['login'] ?? null,
            committedAt: self::date($data['commit']['committer']['date'] ?? $data['commit']['author']['date'] ?? null),
            url: $data['html_url'] ?? null,
        );
    }

    private function headSha(string $fullName, string $branch): string
    {
        $sha = $this->client->get($this->repo($fullName).'/git/ref/heads/'.self::path($branch))['object']['sha'] ?? null;

        if (! is_string($sha) || $sha === '') {
            throw new GitProviderException("Branch {$branch} was not found.", 404);
        }

        return $sha;
    }

    private function repo(string $fullName): string
    {
        if (! preg_match(self::FULL_NAME_PATTERN, $fullName)) {
            throw new GitProviderException('Invalid repository name.', 422);
        }

        return 'repos/'.$fullName;
    }

    /**
     * Encode each segment of a slash-separated ref so it stays a single API path.
     */
    private static function path(string $ref): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $ref)));
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
