<?php

namespace App\Services\Git;

use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\User;
use App\Services\Git\Data\RemotePullRequest;

/**
 * Upserts the local mirror of a provider pull request. Used by syncs, webhooks and
 * PRs opened through the platform, so all three paths produce the same record.
 */
class PullRequestRecorder
{
    public function record(Repository $repository, RemotePullRequest $remote, ?User $openedBy = null): PullRequest
    {
        $pullRequest = PullRequest::query()
            ->where('repository_id', $repository->id)
            ->where('number', $remote->number)
            ->first() ?? new PullRequest;

        // A new head commit invalidates the previous CI result.
        $headChanged = $pullRequest->exists && $pullRequest->head_sha !== $remote->headSha;

        $pullRequest->forceFill([
            'organization_id' => $repository->organization_id,
            'project_id' => $repository->project_id,
            'repository_id' => $repository->id,
        ]);

        $pullRequest->fill([
            'provider' => $repository->provider,
            'external_id' => $remote->id !== '' ? $remote->id : $pullRequest->external_id,
            'number' => $remote->number,
            'title' => $remote->title,
            'state' => $remote->state,
            'is_draft' => $remote->draft,
            'head_branch' => $remote->headBranch,
            'head_sha' => $remote->headSha,
            'base_branch' => $remote->baseBranch,
            'url' => $remote->url,
            'author_login' => $remote->authorLogin,
            'opened_at' => $remote->openedAt,
            'merged_at' => $remote->mergedAt,
            'closed_at' => $remote->closedAt,
            'synced_at' => now(),
        ]);

        if ($headChanged) {
            $pullRequest->checks_status = null;
        }

        if ($openedBy) {
            $pullRequest->opened_via_platform = true;
            $pullRequest->opened_by = $openedBy->id;
        }

        $pullRequest->save();

        return $pullRequest;
    }
}
