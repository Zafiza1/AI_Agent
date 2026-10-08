# Development

## Phases

Phase status and scope are tracked in [architecture.md](architecture.md#9-development-phases).
Build each phase on the previous one; do not skip the foundation for a demo.

## Running with Docker

```bash
docker compose up -d            # build and start everything
docker compose ps               # health of each service
docker compose logs -f backend  # follow logs
```

Code is bind-mounted, so edits reload automatically (Laravel per request, Vite HMR, uvicorn `--reload`).
Composer dependencies are installed into `backend/vendor` on first start.

## Running without Docker

**Backend** (PHP 8.4+, Composer). SQLite works for quick local runs:

```bash
cd backend
composer install
cp .env.example .env
# For SQLite: set DB_CONNECTION=sqlite and remove the other DB_* lines,
# CACHE_STORE=database, QUEUE_CONNECTION=database, SESSION_DRIVER=database
php artisan key:generate
php artisan migrate --seed
php artisan serve                      # http://localhost:8000
php artisan queue:work                 # in another terminal
```

**Frontend** (Node 22+):

```bash
cd frontend
npm install
npm run dev                            # http://localhost:5173, proxies /api to :8000
```

Set `VITE_PROXY_TARGET` to point the dev proxy elsewhere, or `VITE_API_URL` to call an API on another origin.

**Agent** (Python 3.12+):

```bash
cd agent
python -m venv .venv && . .venv/bin/activate
pip install -r requirements-dev.txt
uvicorn app.main:app --reload --port 8001
```

## Tests and checks

| Area | Command |
|---|---|
| Backend tests | `cd backend && php artisan test` |
| Backend style | `cd backend && vendor/bin/pint` |
| Frontend lint / types / build | `cd frontend && npm run lint && npm run build` |
| Agent tests / lint | `cd agent && pytest && ruff check .` |
| All | `sh scripts/test-all.sh` |

Backend feature tests cover authentication, organizations, members, tenant isolation, projects,
environments and secrets, infrastructure, audit logging and system health.

## Conventions

* **Backend:** controllers stay thin; multi-step writes live in services
  (`app/Services`). Every mutating endpoint must call `Gate::authorize(...)` and `AuditLogger::record(...)`.
  New tenant tables need `organization_id` and the `BelongsToOrganization` trait.
* **Frontend:** server state via TanStack Query; permission checks via `useAuth().can(...)`
  (UI only — the API is the authority). Status colors come from `StatusBadge`.
* **Agent:** typed Pydantic models; providers and tools behind interfaces.
* **Git:** branch from `main` with `feature/`, `fix/`, `refactor/`, `security/`, `maintenance/` prefixes.

## Adding a tenant resource (checklist)

1. Migration with `organization_id` FK and an index starting with `organization_id`.
2. Model with `BelongsToOrganization`, `HasUuids`, explicit `#[Fillable]` (never `organization_id`).
3. Permission(s) in `Permission` and the role matrix in `Role`.
4. Controller: `Gate::authorize`, validation, `AuditLogger::record`.
5. Feature tests including a cross-tenant 404 test.
