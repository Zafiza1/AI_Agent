<?php

namespace App\Contracts\Git;

use App\Services\Git\Data\FileChange;
use App\Services\Git\Data\RemoteAccount;
use App\Services\Git\Data\RemoteBranch;
use App\Services\Git\Data\RemoteCommit;
use App\Services\Git\Data\RemotePullRequest;
use App\Services\Git\Data\RemoteRepository;
use App\Services\Git\Data\RemoteWebhook;
use App\Services\Git\GitProviderException;

/**
 * Provider-neutral operations on a git hosting service, bound to one GitConnection.
 * Implementations: GitHubProvider (GitLab / Bitbucket later).
 *
 * Callers are responsible for authorization and branch policy (BranchPolicy);
 * providers only translate calls. Every method throws GitProviderException on failure.
 *
 * Repositories are addressed by their full name ("owner/name").
 */
interface GitProvider
{
    /**
     * Check that the credentials work and describe the account they belong to.
     *
     * @throws GitProviderException
     */
    public function verify(): RemoteAccount;

    /**
     * Repositories the connection can access.
     *
     * @return list<RemoteRepository>
     *
     * @throws GitProviderException
     */
    public function listRepositories(int $limit = 300): array;

    /** @throws GitProviderException */
    public function getRepository(string $fullName): RemoteRepository;

    /**
     * @return list<RemoteBranch>
     *
     * @throws GitProviderException
     */
    public function listBranches(string $fullName, int $limit = 100): array;

    /** @throws GitProviderException */
    public function getBranch(string $fullName, string $branch): RemoteBranch;

    /**
     * Create $branch pointing at the head of $from.
     *
     * @throws GitProviderException
     */
    public function createBranch(string $fullName, string $branch, string $from): RemoteBranch;

    /**
     * @return list<RemoteCommit>
     *
     * @throws GitProviderException
     */
    public function listCommits(string $fullName, string $branch, int $limit = 30): array;

    /**
     * Create one commit on $branch containing $changes (fast-forward only, never forced).
     *
     * @param  list<FileChange>  $changes
     *
     * @throws GitProviderException
     */
    public function commitFiles(string $fullName, string $branch, string $message, array $changes): RemoteCommit;

    /**
     * @return list<RemotePullRequest>
     *
     * @throws GitProviderException
     */
    public function listPullRequests(string $fullName, string $state = 'all', int $limit = 50): array;

    /** @throws GitProviderException */
    public function getPullRequest(string $fullName, int $number): RemotePullRequest;

    /** @throws GitProviderException */
    public function createPullRequest(string $fullName, string $head, string $base, string $title, string $body = '', bool $draft = false): RemotePullRequest;

    /**
     * Register a repository webhook delivering $events to $url, signed with $secret.
     *
     * @param  list<string>  $events
     *
     * @throws GitProviderException
     */
    public function createWebhook(string $fullName, string $url, string $secret, array $events): RemoteWebhook;

    /** @throws GitProviderException */
    public function deleteWebhook(string $fullName, string $webhookId): void;
}
