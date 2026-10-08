# Database

PostgreSQL 16 is the system of record. All primary keys of domain tables are UUIDv7
(time-ordered); `users` keeps Laravel's integer key. Tenant tables carry `organization_id`.

## Phase 1 tables

### organizations
| Column | Type | Notes |
|---|---|---|
| id | uuid PK | |
| name | string | |
| slug | string unique | generated, immutable via API |
| settings | jsonb | organization policy settings (used from Phase 3) |
| created_by | bigint FK users, null on delete | |

### organization_members
| Column | Type | Notes |
|---|---|---|
| id | uuid PK | |
| organization_id | uuid FK cascade | |
| user_id | bigint FK cascade | unique with organization_id |
| role | string | `owner`, `admin`, `maintainer`, `viewer` |

### projects (soft deletes)
| Column | Type | Notes |
|---|---|---|
| organization_id | uuid FK cascade | |
| name, slug | string | slug unique per organization (including trashed) |
| description | text | |
| framework, language, database_type, deployment_type | string | free text describing the stack |
| status | string | `onboarding`, `active`, `paused`, `archived` |
| ai_context | jsonb | project memory given to agents (`notes`) |
| created_by | bigint FK users | |

`repository_url`, `repository_provider` and `default_branch` are exposed on the project API but
stored on its **primary repository**, so there is one source of truth.

### repositories
`project_id`, `provider` (`github`/`gitlab`/`bitbucket`/`other`), `url` (unique per project),
`full_name` (`owner/name`), `default_branch`, `is_primary`, `connection_status`
(`not_connected`/`connected`/`error`), `external_id` (provider id, Phase 2).

### environments
`project_id`, `name` (unique per project), `type` (`development`/`staging`/`production`), `url`,
`branch`, `health_check_url`, `is_protected`, `requires_approval`, `deployment_config` (jsonb).
Production defaults to protected and approval-gated.

### environment_variables
`environment_id`, `key` (unique per environment, UPPER_SNAKE_CASE), `value` (ciphertext written by
the `SecretStore`), `is_secret`, `updated_by`.

### servers · databases · services
Registry of infrastructure per project, optionally linked to an environment (and services/databases
to a server). Columns: see the migration
[2026_10_07_100004_create_infrastructure_tables.php](../backend/database/migrations/2026_10_07_100004_create_infrastructure_tables.php).
No credentials are stored in these tables.

### audit_logs (append-only)
| Column | Notes |
|---|---|
| organization_id, project_id, user_id | plain columns, **no foreign keys**, so records survive deletions |
| actor_type | `user`, `agent`, `system` |
| agent_id, tool, approval_id | filled from Phase 3 |
| action | dotted verb, e.g. `project.created`, `environment.variables_updated` |
| target_type, target_id | the affected model |
| risk_level | `low`, `medium`, `high`, `critical` |
| result | `success`, `failure`, `denied` |
| metadata | jsonb, redacted before insert |
| ip_address, user_agent, created_at | request context |

The `AuditLog` model throws on update and delete.

## Planned tables (later phases)

| Phase | Tables |
|---|---|
| 2 | `git_connections`, `webhook_deliveries`, `pull_requests` |
| 3 | `agent_tasks`, `agent_runs`, `agent_steps`, `agent_tool_calls`, `approvals`, `organization_policies` |
| 4 | `sandboxes` |
| 6 | `deployments` (`commit_sha`, `image_tag`, `environment_id`, `deployed_by`, `deployed_at`, `status`), `rollbacks` |
| 7 | `events`, `incidents`, `incident_timeline`, `metrics_snapshots`, `alerts` |
| 8 | `maintenance_schedules`, `maintenance_reports` |

## Conventions

* Every tenant model uses `App\Models\Concerns\BelongsToOrganization`: a global scope filters by
  the resolved organization and `organization_id` is set from it on create (never from input).
* Indexes lead with `organization_id` for tenant-scoped listing.
* Seed data: [DatabaseSeeder](../backend/database/seeders/DatabaseSeeder.php).
