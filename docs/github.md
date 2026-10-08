# GitHub integration (Phase 2)

The control plane talks to GitHub through a provider-neutral contract,
[`App\Contracts\Git\GitProvider`](../backend/app/Contracts/Git/GitProvider.php). `GitHubProvider` is
the only implementation today; GitLab or Bitbucket can be added without touching callers.
Agents (Phase 3) will reach these operations through the Tool Gateway, never with raw credentials.

```text
Dashboard / Tool Gateway
        │  REST (tenant + RBAC + BranchPolicy + audit)
        ▼
RepositoryGitController ──► RepositoryLinker / GitProviderFactory ──► GitHubProvider ──► GitHub REST API
                                                                          ▲
GitHub ──► /api/webhooks/github[...] ──► webhook_deliveries ──► queue ──► GitHubWebhookProcessor
            (HMAC verified, idempotent)                                    │
                                                    pull_requests, repository state, GitHubEventReceived
```

## Connecting an organization

An organization has one or more **git connections** (`git_connections`):

| Type | When to use | Credentials |
|---|---|---|
| **GitHub App** (recommended) | Teams and customers. Fine-grained, per-repository access; tokens expire after an hour. | Installation tokens minted on demand from the App's private key; cached encrypted in Redis until 5 minutes before expiry. Nothing is stored per organization. |
| **Personal access token** | Personal projects, or before an App is registered. | The token, verified against `GET /user`, encrypted at rest (`encrypted` cast), hidden from serialization, never returned by the API. |

Only `owner` and `admin` (`integrations.manage`) can add, verify or remove connections. Every member
can see that a connection exists, never its secret.

### GitHub App installation flow

1. **Settings → Integrations → Install GitHub App** calls `POST /api/git-connections/github/install`.
   The backend stores a single-use `state` (15 minutes) bound to the organization **and** the user,
   and returns `https://github.com/apps/<slug>/installations/new?state=…`.
2. GitHub redirects the browser to the App's **Setup URL** (the dashboard's
   `/integrations/github/callback`) with `installation_id`, `setup_action`, `code` and `state`.
3. The dashboard posts these to `POST /api/git-connections/github/callback`. The backend:
   * consumes the `state` (wrong user, wrong organization, expired or reused → 422),
   * exchanges `code` for a user token and checks `GET /user/installations` contains the
     installation — so nobody can link an installation id they merely guessed,
   * reads the installation with an App JWT (RS256) and stores the connection.
   An installation can be linked to **one** organization only.

`setup_action=request` (a GitHub member asked an owner to approve) is acknowledged with 202 and
nothing is stored.

### Registering the platform's GitHub App (operator, once)

In GitHub: *Settings → Developer settings → GitHub Apps → New GitHub App*.

| Setting | Value |
|---|---|
| Callback URL | `<dashboard>/integrations/github/callback` |
| Request user authorization (OAuth) during installation | **enabled** (required, see step 3) |
| Setup URL | `<dashboard>/integrations/github/callback` (GitHub uses the Callback URL instead while OAuth during installation is on; set both to the same page) · redirect on update: enabled |
| Webhook URL | `<public backend>/api/webhooks/github` |
| Webhook secret | a long random string → `GITHUB_APP_WEBHOOK_SECRET` |
| Repository permissions | Contents: read & write · Pull requests: read & write · Metadata: read · Checks: read · Actions: read · Issues: read |
| Subscribe to events | Push, Pull request, Issues, Check suite, Workflow run, Repository |

Then generate a private key and a client secret and set (root `.env` under Docker):

```dotenv
GITHUB_APP_ID=123456
GITHUB_APP_SLUG=my-maintenance-platform
GITHUB_APP_CLIENT_ID=Iv23li...
GITHUB_APP_CLIENT_SECRET=...
GITHUB_APP_PRIVATE_KEY_PATH=/var/www/html/storage/app/private/github-app.pem
GITHUB_APP_WEBHOOK_SECRET=...
```

The App option appears in the dashboard only when **all** of these are set
(`GET /api/meta` → `integrations.github_app.enabled`). GitHub Enterprise Server works by setting
`GITHUB_API_URL` and `GITHUB_WEB_URL`.

### Personal access tokens

Use a fine-grained token limited to the repositories you want to maintain, with **Contents**,
**Pull requests** and **Metadata** access, plus **Webhooks: read & write** if the platform should
create repository webhooks. Classic tokens need `repo` (and `admin:repo_hook` for webhooks).

## Connecting a repository

Repositories are registered on a project by URL (Phase 1). **Connect** links one to a connection:

1. `GET /repos/{owner}/{name}` with the connection must succeed (404 → "not accessible with this
   connection"); the canonical name, default branch, visibility and GitHub id are stored.
2. Webhooks:
   * GitHub App connections receive the App's webhook (`webhook_status: managed`).
   * Token connections get a repository webhook with its **own random secret**
     (`webhook_status: active`). If GitHub refuses (missing permission, or a URL it cannot reach
     such as `localhost`), the repository stays connected with `webhook_status: failed` and the
     reason; use **Sync** until a public URL is configured (`GITHUB_WEBHOOK_BASE_URL`, e.g. a tunnel).
3. Recent pull requests (50) are imported.

Disconnecting (or deleting the repository / connection) removes the platform's repository
webhook and the stored secret.

## Branch policy

[`BranchPolicy`](../backend/app/Support/Git/BranchPolicy.php) applies to every write the platform makes:

* never the repository's default branch, `main`, `master`, `develop`, `development`, `production`,
  `staging`, `trunk`, `release/*` or `hotfix/*`;
* new branches, commit targets and pull request heads must be **work branches**:
  `fix/`, `feature/`, `refactor/`, `security/` or `maintenance/` + a lowercase slug;
* branches protected on GitHub are refused for commits even if the name is a work branch.

Refusals return 422 and are audited as `repository.write_denied` (`result: denied`, risk `high`).
Changes reach protected branches only through a reviewed pull request.

## Operations

| Operation | Endpoint | Permission | Audit / risk |
|---|---|---|---|
| List branches (with `is_default`, `is_protected`, `is_writable`) | `GET /repositories/{id}/branches` | projects.view | — |
| Create branch | `POST /repositories/{id}/branches` | repositories.write | `repository.branch_created`, `git.create_branch`, medium |
| List commits | `GET /repositories/{id}/commits?branch=` | projects.view | — |
| Commit files | `POST /repositories/{id}/commits` | repositories.write | `repository.committed`, `git.commit`, medium |
| Open pull request | `POST /repositories/{id}/pull-requests` | repositories.write | `pull_request.created`, `git.create_pull_request`, medium |
| Sync metadata + PRs | `POST /repositories/{id}/sync` | projects.update | `repository.synced` |

Commits use the Git Data API (blobs → tree → commit → ref update with `force: false`), so a commit
never rewrites history and fails if the branch moved concurrently. Paths are validated (no absolute
paths, no `.`/`..` segments, nothing under `.git/`), at most 100 files and 5 MB per commit.

## Webhooks

| Endpoint | Secret | Used by |
|---|---|---|
| `POST /api/webhooks/github` | `GITHUB_APP_WEBHOOK_SECRET` | GitHub App |
| `POST /api/webhooks/github/repositories/{repositoryId}` | the repository's own secret | token connections |

* Nothing is trusted before `X-Hub-Signature-256` verifies (`hash_equals` over the raw body);
  unknown repositories answer 404 like repositories without a webhook.
* `X-GitHub-Delivery` is the idempotency key (unique). Redeliveries return 200 and are not reprocessed.
* Deliveries are stored in `webhook_deliveries` and processed by `ProcessGitHubWebhook` on the
  queue (worker). Deliveries for unknown installations are stored as `ignored`.
* The payload is never exposed by the API; `GET /repositories/{id}/webhook-deliveries` lists
  event, action, status and error for debugging.

Processing:

| GitHub event | Effect | Normalized event(s) |
|---|---|---|
| `pull_request` | upsert `pull_requests` (state, draft, head/base, author) | `github.pull_request.<action>` |
| `push` | default branch: `last_commit_sha`, `last_pushed_at` | `github.push` |
| `check_suite`, `workflow_run` | `checks_status` on PRs with that head SHA / number | on failure: `ci.build.failed`, `github.pull_request.failed` |
| `issues` (`opened`) | — | `github.issue.created` |
| `repository` | follow renames/transfers; flag deleted/archived | — |
| `installation` (`deleted`, `suspend`, `unsuspend`) | connection status; repositories flagged on uninstall; audited as system | — |
| `installation_repositories` (`removed`) | flag the removed repositories | — |

Normalized events are dispatched as `App\Events\GitHubEventReceived` (name, organization,
project, repository, delivery, small summary). Phase 3 turns them into agent tasks; Phase 7 moves
them onto the event bus.

## Local development

GitHub cannot deliver webhooks to `localhost`. Either rely on **Sync**, or expose the backend with
a tunnel (`cloudflared tunnel --url http://localhost:8000`, smee.io, ngrok) and set
`GITHUB_WEBHOOK_BASE_URL` (token connections) or the App's webhook URL to it.

Tests fake every GitHub call (`Http::fake`) — see
[tests/Feature/Git](../backend/tests/Feature/Git) and
[InteractsWithGitHub](../backend/tests/Concerns/InteractsWithGitHub.php).
