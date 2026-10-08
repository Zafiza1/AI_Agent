# Security model

## Implemented in Phase 1

### Authentication
* Sanctum personal access tokens (bearer), default lifetime 7 days (`SANCTUM_TOKEN_EXPIRATION`).
* Passwords: bcrypt, minimum 10 characters with letters and numbers.
* Login/registration rate limit: 10 per minute per email+IP. API limit: 240 per minute per user.
* Failed logins are audited with the email only, never the password.
* The dashboard keeps the token in `localStorage`. This is simple but exposed to XSS; the frontend
  renders no untrusted HTML. Moving to Sanctum's cookie-based SPA auth is tracked for production hardening.

### Multi-tenant isolation (two independent layers)
1. **Membership gate.** `X-Organization-Id` is untrusted input. `ResolveOrganization` accepts it
   only if the user is a member, and answers 403 identically for missing and foreign
   organizations (no tenant enumeration).
2. **Data scoping.** `BelongsToOrganization` adds a global scope on `organization_id` and forces
   `organization_id` from the resolved tenant on insert; updates that move a row between tenants
   throw. Route model binding runs after tenant resolution, so foreign IDs return 404.

Additionally, sibling references (`environment_id`, `server_id`) are validated against the same
project, and malformed IDs are rejected before reaching the database.
[TenancyIsolationTest](../backend/tests/Feature/TenancyIsolationTest.php) covers these paths.

### Secrets
* `App\Contracts\SecretStore` is the only path to plaintext. The default implementation encrypts
  with the app key (AES-256-CBC + MAC); a Vault/KMS implementation can be bound without changing callers.
* Secret values are **write-only** over the API (`value: null` in responses), hidden from model
  serialization, never logged, and excluded from audit metadata (only keys are recorded).
* Infrastructure records hold no credentials.
* Phase 3 rule: secrets are injected into sandboxes/tools by reference; the LLM never sees them,
  and the full `.env` is never sent to a model.

### Audit
* Every mutating endpoint writes an audit record via `AuditLogger` with actor, target, risk and result.
* Metadata keys matching password/secret/token/api_key/credential/authorization/cookie/value are redacted.
* The model rejects updates and deletes; audit rows have no foreign keys so they outlive deletions.

### Other controls
* CORS restricted to `CORS_ALLOWED_ORIGINS`.
* JSON error responses for the API; stack traces only with `APP_DEBUG=true`.
* System health endpoint logs dependency errors but never returns hostnames or DSNs.
* Development seeder refuses to run in production.

## Risk levels and policy (Phase 3+)

| Level | Examples | Default |
|---|---|---|
| LOW | `read_file`, `search_code`, `read_log`, `list_files`, `run_test` | allow |
| MEDIUM | `modify_code` (sandbox), `create_branch`, `install_dependency`, staging restart | allow or approval, per organization policy |
| HIGH | DB migration, production restart/deploy/config | approval required |
| CRITICAL | drop/truncate database, destroy server, destructive production commands | denied |

Default rules (non-overridable unless stated):

* No arbitrary production shell: only allow-listed, parameterized operations.
* Database tool: read-only by default; a SQL classifier blocks `DROP`, `TRUNCATE`, `DELETE`/`UPDATE`
  without `WHERE`, and DDL outside reviewed migrations.
* No direct commits to the default branch; agents work on `fix/`, `feature/`, `refactor/`,
  `security/`, `maintenance/` branches and open PRs.
* Sandboxes have no access to production networks and only egress allow-listed registries.
* AI confidence never overrides policy.
* Agent runs are bounded (steps, tool calls, retries = 3, runtime, tokens, cost); on exhaustion → `REQUIRES_HUMAN`.
