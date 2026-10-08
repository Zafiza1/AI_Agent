# Agent engine

Python/FastAPI service that will run the AI agents. In Phase 1 it exposes only
`GET /health` and its configuration, so the platform's service boundary, Docker
setup and health checks are in place before orchestration is built.

Package layout reserved for Phase 3+ (see [../docs/agent-architecture.md](../docs/agent-architecture.md)):

| Package | Responsibility |
|---|---|
| `app/orchestrator` | Task lifecycle state machine, budgets, retries, escalation |
| `app/agents` | `Agent` interface and Code/Debug/Database/DevOps/Security/Reviewer agents |
| `app/tools` | `Tool` interface and the Tool Gateway |
| `app/policies` | Policy engine: ALLOW / DENY / REQUIRE_APPROVAL |
| `app/memory` | Project memory, task memory, retrieval |
| `app/sandbox` | Per-task Docker workspaces |
| `app/services` | `LLMProvider` abstraction and control-plane client |

## Run locally

```bash
python -m venv .venv && . .venv/bin/activate   # Windows: .venv\Scripts\activate
pip install -r requirements-dev.txt
uvicorn app.main:app --reload --port 8001
pytest
```
