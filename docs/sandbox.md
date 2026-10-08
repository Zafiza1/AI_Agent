# Sandbox (design — Phase 4)

All code modification and test execution happens in an ephemeral, per-task workspace.

## Lifecycle

```text
create workspace  task-<id>/{source,tests,logs,docker-compose.yml}
  → clone repository (scoped, read-only deploy token; shallow; branch from default)
  → start containers from the project's own compose/Dockerfile or a detected template
  → install dependencies (registry allow-list)
  → agent edits files, runs tests, build, lint, security scan
  → export diff + artifacts (test reports, logs)
  → destroy containers, networks, volumes and workspace
```

## Isolation

* One Docker network per sandbox; no route to production networks or the platform's internal services.
* Egress restricted to package registries and the Git provider.
* No host mounts beyond the workspace; non-root user; dropped capabilities; read-only root filesystem
  where the stack allows; CPU, memory, PID and disk quotas; wall-clock timeout.
* Secrets: only test fixtures or explicitly marked *sandbox* variables are injected; production
  secrets never enter a sandbox.
* The `sandbox-runner` service owns the Docker socket; the agent engine talks to it through a narrow API
  (create, exec allow-listed command, read file, write file, collect, destroy).

## Cleanup guarantees

A reaper destroys sandboxes past their TTL even if the task crashed, and every create/destroy is audited.
