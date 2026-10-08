<?php

namespace App\Http\Controllers\Api\Git;

use App\Http\Controllers\Controller;
use App\Http\Resources\PullRequestResource;
use App\Models\PullRequest;
use App\Support\Rbac\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Pull requests across every project of the organization.
 */
class PullRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize(Permission::ProjectsView->value);

        $filters = $request->validate([
            'project_id' => ['nullable', 'uuid'],
            'repository_id' => ['nullable', 'uuid'],
            'state' => ['nullable', Rule::in(['open', 'closed', 'merged'])],
            'opened_via_platform' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $pullRequests = PullRequest::query()
            ->with(['repository', 'project', 'opener'])
            ->when($filters['project_id'] ?? null, fn ($q, $id) => $q->where('project_id', $id))
            ->when($filters['repository_id'] ?? null, fn ($q, $id) => $q->where('repository_id', $id))
            ->when($filters['state'] ?? null, fn ($q, $state) => $q->where('state', $state))
            ->when(isset($filters['opened_via_platform']), fn ($q) => $q->where('opened_via_platform', $request->boolean('opened_via_platform')))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $q->where(fn ($q) => $q->whereRaw('lower(title) like ?', [$term])->orWhereRaw('lower(head_branch) like ?', [$term]));
            })
            ->orderByRaw('coalesce(opened_at, created_at) desc')
            ->paginate($filters['per_page'] ?? 25);

        return PullRequestResource::collection($pullRequests);
    }

    public function show(PullRequest $pullRequest): PullRequestResource
    {
        Gate::authorize(Permission::ProjectsView->value);

        return new PullRequestResource($pullRequest->load(['repository', 'project', 'opener']));
    }
}
