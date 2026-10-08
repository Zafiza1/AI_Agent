# Monitoring and events (design — Phase 7)

## Platform health (implemented)

`GET /api/system/health` checks PostgreSQL, Redis and the agent engine and is shown on the
dashboard overview. Container health checks are defined in `docker-compose.yml`.

## Project observability

Inputs: application logs, server logs, Docker logs, error events, health checks, metrics,
deployment events and CI/CD events — via a lightweight collector on the customer's servers,
webhooks (`/api/webhooks/github`, `/api/webhooks/ci`) and a generic `/api/events` endpoint
authenticated with per-project ingest tokens.

Minimum metrics: CPU, RAM, disk, HTTP response time, HTTP error rate, request count, container
status, database status. Registered servers, databases and services carry a `status`
(`healthy, warning, critical, offline, unknown`) that these signals will update.

## Event system

```text
event (e.g. container.crashed) → Redis stream → classifier (dedupe, severity, project match)
  → incident (if severity ≥ threshold) → agent task → orchestrator
```

Event types: `github.issue.created`, `github.pull_request.failed`, `ci.build.failed`,
`server.cpu.high`, `server.disk.high`, `application.error`, `database.slow_query`,
`container.crashed`, `deployment.failed`, `health_check.failed`.

## Realtime

The dashboard will subscribe over WebSockets (Laravel Reverb) to `task.*`, `tool.*`, `test.*`,
`approval.required`, `deployment.*` and `incident.created`, on private channels authorized per organization.

## Self-healing

Policy-driven: e.g. *restart a crashed staging container automatically; restart in production
only if the organization policy allows it*. Code changes, database changes and production
deployments always follow the approval policy.
