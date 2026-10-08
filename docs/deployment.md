# Deployment (design — Phase 6)

## Platform deployment

Phase 1 provides the development stack (`docker-compose.yml`). Production deployment of the
platform itself will use multi-stage images (PHP-FPM + Nginx for the API, static build for the
dashboard served by a CDN or Nginx, gunicorn/uvicorn workers for the agent), managed PostgreSQL
and Redis, and secrets from the host's secret manager. Manifests will live in `infrastructure/`.

## Customer project deployments

```text
PR merged → build image (tag = commit SHA) → deploy staging → health check → smoke test
  → AI review of results → human approval (production requires_approval = true by default)
  → deploy production → monitor error rate / latency for a watch window
  → regression? stop rollout → rollback to previous deployment → health check → incident → notify
```

Each deployment record stores `deployment_id, project_id, environment_id, commit_sha, image_tag,
deployed_by (user or agent), approval_id, deployed_at, status, previous_deployment_id`.

Rollback redeploys `previous_deployment_id`'s image tag; it is itself a deployment record and audited.
Rollback of production is HIGH risk; when triggered automatically by a regression policy, the
policy itself is the pre-approval and the action is recorded with that policy id.
