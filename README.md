# AI Software Maintenance Platform

A multi-tenant platform where AI agents maintain software (debugging, fixing, testing, reviewing,
deploying and monitoring) under human control: every risky action passes a policy engine, an
approval workflow and an append-only audit log, and code changes happen only in sandboxes.

> **Status: Phases 1–2 are implemented.** Foundation (authentication, organizations, RBAC, the
> project registry, encrypted secrets, audit logging, dashboard, Docker stack) and the GitHub
> integration (GitHub App or token connections, repository linking, branches, commits, pull
> requests, signed webhooks). AI agents arrive in Phase 3. See the [roadmap](#roadmap).

## Architecture

```text
React dashboard ──► Laravel control plane ──► PostgreSQL
                         │  auth · tenancy · RBAC · audit · secrets
                         ├──► Redis (queues, cache, events)
                         └──► Agent engine (FastAPI) ──► Tool Gateway ──► GitHub / Sandbox / Servers
```

| Service | Path | Stack | Port |
|---|---|---|---|
| Dashboard | [frontend/](frontend/) | React 19, TypeScript, Vite, Tailwind CSS v4, TanStack Query | 5173 |
| Control plane API | [backend/](backend/) | Laravel 13, PHP 8.4, Sanctum | 8000 |
| Queue worker / scheduler | [backend/](backend/) | Laravel queue + scheduler | — |
| Agent engine | [agent/](agent/) | Python 3.12, FastAPI | 8001 |
| Database | — | PostgreSQL 16 | 5432 |
| Cache / queue | — | Redis 7 | 6379 |

Design documents: [architecture](docs/architecture.md) · [database](docs/database.md) ·
[security](docs/security.md) · [permissions](docs/permissions.md) · [API](docs/api.md) · [GitHub](docs/github.md) ·
[agents](docs/agent-architecture.md) · [tools](docs/tools.md) · [sandbox](docs/sandbox.md) ·
[deployment](docs/deployment.md) · [monitoring](docs/monitoring.md) · [development](docs/development.md)

## Quick start (Docker)

Requirements: Docker with Compose v2.

```bash
cp .env.example .env            # optional: change ports / Postgres password
docker compose up -d
```

**Windows with Docker inside WSL** (no Docker Desktop): run the commands from the WSL shell,
e.g. `wsl` then `cd /mnt/d/AI_Agent && docker compose up -d`. Keep a WSL terminal open while you
work, since WSL stops its VM (and Docker) shortly after the last session closes. If a port is already
taken by another project, change it in `.env` (e.g. `POSTGRES_PORT=5433`).

On first start the backend installs Composer dependencies, creates `backend/.env`, generates
`APP_KEY`, runs migrations and seeds development data. Then open **http://localhost:5173**.

| Account | Password | Role |
|---|---|---|
| `owner@example.com` | `password` | Owner of *Acme Engineering* |
| `admin@example.com` | `password` | Admin |
| `maintainer@example.com` | `password` | Maintainer |
| `viewer@example.com` | `password` | Viewer |
| `outsider@example.com` | `password` | Owner of *Globex* (a separate tenant, useful for testing isolation) |

Seed accounts exist only in development (`SEED_ON_START=true`, and the seeder refuses to run in
production). Change `SEED_USER_PASSWORD` in `backend/.env` if the stack is reachable by others.

Useful commands:

```bash
docker compose logs -f backend                      # API logs
docker compose exec backend php artisan test        # backend tests
docker compose exec backend php artisan migrate:fresh --seed
docker compose exec agent pytest                    # agent tests
docker compose down                                 # stop (add -v to drop data volumes)
```

## Configuration

| File | Purpose |
|---|---|
| [.env.example](.env.example) | Compose settings: ports, Postgres credentials, seeding, agent token |
| [backend/.env.example](backend/.env.example) | Laravel settings: database, Redis, CORS, token lifetime |
| `AGENT_*` variables | Agent engine settings ([agent/app/config.py](agent/app/config.py)) |

Secrets never go in source code. Project secrets are stored encrypted through the `SecretStore`
contract and are write-only over the API.

**AI configuration** (LLM provider keys, budgets) starts in Phase 3. The agent engine already
exposes its default budgets (`AGENT_MAX_STEPS`, `AGENT_MAX_TOOL_CALLS`, `AGENT_MAX_RETRIES`,
`AGENT_MAX_RUNTIME_SECONDS`).

**GitHub integration** ([docs/github.md](docs/github.md)). An admin connects GitHub under
**Settings → Integrations**, either with a fine-grained personal access token (works out of the
box) or by installing the platform's GitHub App (set the `GITHUB_APP_*` variables in `.env`).
Repositories registered on a project are then connected from its **Repositories** tab: the
platform syncs pull requests, lists branches and commits, opens `fix/…`-style branches and pull
requests (never writing to the default branch), and receives signed webhooks. GitHub cannot reach
`localhost`; set `GITHUB_WEBHOOK_BASE_URL` to a tunnel URL to receive webhooks in development.

## Development without Docker

See [docs/development.md](docs/development.md). In short: PHP 8.4+ with `pdo_pgsql`/`pdo_sqlite`,
Composer, Node 22+, and Python 3.12+.

```bash
cd backend && composer install && cp .env.example .env && php artisan key:generate
php artisan migrate --seed && php artisan serve          # API on :8000
cd frontend && npm install && npm run dev                 # dashboard on :5173 (proxies /api)
cd agent && pip install -r requirements-dev.txt && uvicorn app.main:app --port 8001
```

## Testing

```bash
sh scripts/test-all.sh         # everything below
cd backend && php artisan test && vendor/bin/pint --test
cd frontend && npm run lint && npm run build
cd agent && pytest && ruff check .
```

CI ([.github/workflows/ci.yml](.github/workflows/ci.yml)) runs the backend suite on SQLite and
PostgreSQL, plus the frontend and agent checks.

## Deployment

Phase 1 ships a development stack only. Production images, the deployment pipeline and
rollback are part of Phase 6; the target design is in [docs/deployment.md](docs/deployment.md).

## Roadmap

| Phase | Scope | Status |
|---|---|---|
| 1 | Foundation: Docker, Laravel, React, PostgreSQL, Redis, auth, organizations, RBAC, project registry, secrets, audit | Done |
| 2 | GitHub integration: app install, webhooks, branches, commits, pull requests | Done |
| 3 | Agent engine: orchestrator, LLM providers, tool gateway, policy engine, tasks, approvals | Next |
| 4 | Sandbox: per-task Docker workspaces, clone, test, build | Planned |
| 5 | Maintenance agents: debug, fix, test, security scan, review, PR | Planned |
| 6 | Infrastructure: servers, Docker, logs, deployments, rollback | Planned |
| 7 | Observability: metrics, errors, incidents, alerts, events, realtime | Planned |
| 8 | Autonomous maintenance loop | Planned |
