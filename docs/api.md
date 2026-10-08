# API reference (Phase 1)

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
| DELETE | `/repositories/{id}` 🏢 | projects.update | |

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

## Planned endpoints

Phase 2–7 endpoints (`/tasks`, `/approvals/{id}/approve`, `/deployments/{id}/rollback`,
`/incidents`, `/monitoring`, `/webhooks/github`, `/webhooks/ci`, `/events`) follow the same
conventions and are specified with their phase.
