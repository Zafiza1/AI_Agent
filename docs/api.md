# API reference (Phases 1–2)

Base URL: `/api`. All requests and responses are JSON (`Accept: application/json`).

**Authentication:** `Authorization: Bearer <token>` from login/register.
**Tenant context:** endpoints marked 🏢 require `X-Organization-Id: <organization uuid>`; the caller
must be a member (403 otherwise). Resource IDs from another organization return 404.

**Errors:** `401` unauthenticated · `403` forbidden · `404` not found · `422` validation
(`{ message, errors: { field: [msg] } }`) · `429` rate limited.

**Pagination** (`?page=&per_page=`): `{ data: [...], links: {...}, meta: { current_page, last_page, per_page, total } }`.

## Auth

| Method | Path | Body | Notes |
|---|---|---|---|
| POST | `/auth/register` | `name, email, password, password_confirmation` | 201 → `{ token, token_type, user, organizations }` |
| POST | `/auth/login` | `email, password` | → `{ token, token_type, user, organizations }` |
| POST | `/auth/logout` | — | revokes the current token |
| GET | `/auth/me` | — | `{ user, organizations[] }` with `role` and `permissions` per organization |

## Platform

| Method | Path | Notes |
|---|---|---|
| GET | `/meta` | roles with permissions, enum values |
| GET | `/system/health` | `{ status: healthy\|degraded, checks: { database, redis, agent } }` |

## Organizations

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/organizations` | — | organizations of the current user |
| POST | `/organizations` | — | `name`; creator becomes owner |
| GET | `/organization` 🏢 | organization.view | current organization incl. role/permissions |
| PATCH | `/organization` 🏢 | organization.update | `name`, `settings` |
| DELETE | `/organization` 🏢 | organization.delete | body `confirm` = slug |
| GET | `/organization/members` 🏢 | members.view | |
| POST | `/organization/members` 🏢 | members.manage | `email` (existing user), `role` |
| PATCH | `/organization/members/{id}` 🏢 | members.manage | `role` |
| DELETE | `/organization/members/{id}` 🏢 | members.manage (or self) | |

## Dashboard and audit

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/dashboard/overview` 🏢 | projects.view | counts, infrastructure health, recent activity (if audit.view) |
| GET | `/audit-logs` 🏢 | audit.view | filters: `project_id, action` (prefix), `actor_type, result, user_id, from, to` |

## Projects

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/projects` 🏢 | projects.view | `search, status, page, per_page` |
| POST | `/projects` 🏢 | projects.create | see body below |
| GET | `/projects/{id}` 🏢 | projects.view | includes primary repository, environments, counts |
| PATCH | `/projects/{id}` 🏢 | projects.update | any create field, plus `status` |
| DELETE | `/projects/{id}` 🏢 | projects.delete | soft delete |

Create body:

```json
{
  "name": "SIG Website",
  "description": "Public website",
  "repository_url": "https://github.com/acme/sig-website",
  "repository_provider": "github",
  "default_branch": "main",
  "framework": "Laravel + React",
  "language": "PHP / TypeScript",
  "database_type": "mysql",
  "deployment_type": "docker",
  "ai_context": { "notes": "Do not modify the production database directly." },
  "create_default_environments": true
}
```

## Repositories

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/projects/{id}/repositories` 🏢 | projects.view | |
| POST | `/projects/{id}/repositories` 🏢 | projects.update | `url, provider?, default_branch?, is_primary?` |
| PATCH | `/repositories/{id}` 🏢 | projects.update | `default_branch, is_primary: true` |
| DELETE | `/repositories/{id}` 🏢 | projects.update | removes the platform webhook first |
| GET | `/repositories` 🏢 | projects.view | all repositories of the organization; `search, connection_status` |

## Environments and variables

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/projects/{id}/environments` 🏢 | projects.view | |
| POST | `/projects/{id}/environments` 🏢 | environments.manage (+ manage_protected if protected) | `name, type, url, branch, health_check_url, is_protected, requires_approval, deployment_config` |
| GET | `/environments/{id}` 🏢 | projects.view | |
| PATCH | `/environments/{id}` 🏢 | manage / manage_protected | protection flags need manage_protected |
| DELETE | `/environments/{id}` 🏢 | manage / manage_protected | |
| GET | `/environments/{id}/variables` 🏢 | projects.view | secret `value` is always `null` |
| PUT | `/environments/{id}/variables` 🏢 | secrets.manage + env manage | `{ variables: [{ key, value, is_secret }] }` upsert by key |
| DELETE | `/environments/{id}/variables/{variableId}` 🏢 | secrets.manage + env manage | |

## Infrastructure registry

Same shape for `servers`, `databases`, `services`:

| Method | Path | Permission |
|---|---|---|
| GET | `/projects/{id}/{kind}` 🏢 | projects.view |
| POST | `/projects/{id}/{kind}` 🏢 | infrastructure.manage |
| GET / PATCH / DELETE | `/{kind}/{id}` 🏢 | projects.view / infrastructure.manage |

Fields: servers `name, environment_id, hostname, ip_address, provider, os, connection_type, status`;
databases `name, engine, environment_id, server_id, version, host, port, database_name, status`;
services `name, type, runtime, environment_id, server_id, container_name, port, health_check_url, status`.

## Git connections (Phase 2)

Credentials are never returned. Provider failures answer **422** (bad input, not accessible,
rejected credentials) or **502** (GitHub unreachable) with a user-safe `message`.
Flows and setup: [github.md](github.md).

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/git-connections` 🏢 | organization.view | with `repositories_count` |
| POST | `/git-connections` 🏢 | integrations.manage | `{ provider: "github", token, name? }`; the token is verified first |
| POST | `/git-connections/github/install` 🏢 | integrations.manage | → `{ data: { url } }`, GitHub install URL with a single-use state |
| POST | `/git-connections/github/callback` 🏢 | integrations.manage | `installation_id, setup_action, code, state` → 201 connection (202 for `setup_action=request`) |
| PATCH | `/git-connections/{id}` 🏢 | integrations.manage | `name` |
| POST | `/git-connections/{id}/verify` 🏢 | integrations.manage | refreshes `status`, `last_error` |
| DELETE | `/git-connections/{id}` 🏢 | integrations.manage | disconnects its repositories first |
| GET | `/git-connections/{id}/remote-repositories` 🏢 | projects.update | repositories the connection can access |

## Repository operations (Phase 2)

| Method | Path | Permission | Notes |
|---|---|---|---|
| POST | `/repositories/{id}/connect` 🏢 | projects.update | `git_connection_id, create_webhook?` (default true) |
| POST | `/repositories/{id}/disconnect` 🏢 | projects.update | |
| POST | `/repositories/{id}/sync` 🏢 | projects.update | metadata + last 50 pull requests |
| GET | `/repositories/{id}/branches` 🏢 | projects.view | `name, sha, is_default, is_protected, is_writable` |
| POST | `/repositories/{id}/branches` 🏢 | repositories.write | `name` (work branch), `from?` (default branch) |
| GET | `/repositories/{id}/commits` 🏢 | projects.view | `branch?, limit?` (≤ 100) |
| POST | `/repositories/{id}/commits` 🏢 | repositories.write | `branch, message, files: [{ path, content }` or `{ path, delete: true }]` (≤ 100 files, 5 MB) |
| GET | `/repositories/{id}/pull-requests` 🏢 | projects.view | `state?`, paginated |
| POST | `/repositories/{id}/pull-requests` 🏢 | repositories.write | `head` (work branch), `base?`, `title, body?, draft?` |
| GET | `/repositories/{id}/webhook-deliveries` 🏢 | projects.view | last 50, without payloads |
| GET | `/pull-requests` 🏢 | projects.view | `project_id, repository_id, state, opened_via_platform, search, page, per_page` |
| GET | `/pull-requests/{id}` 🏢 | projects.view | |

Branch writes follow the [branch policy](github.md#branch-policy); violations are 422.

## Webhooks (public, signature-authenticated)

| Method | Path | Notes |
|---|---|---|
| POST | `/webhooks/github` | GitHub App deliveries; 404 when the App is not configured |
| POST | `/webhooks/github/repositories/{id}` | repository webhooks created for token connections |

`401` bad signature · `400` missing delivery headers · `202` accepted · `200` ping or duplicate delivery.

## Planned endpoints

Phase 3–7 endpoints (`/tasks`, `/approvals/{id}/approve`, `/deployments/{id}/rollback`,
`/incidents`, `/monitoring`, `/webhooks/ci`, `/events`) follow the same conventions and are
specified with their phase.
