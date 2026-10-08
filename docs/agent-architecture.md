# Agent architecture (design — Phase 3+)

Phase 1 ships only the agent service boundary (`GET /health`, configuration). This document is
the contract the Phase 3 implementation follows.

## Flow

```text
Laravel: task created (API, chat, webhook, event, schedule)
   │  push TaskEnvelope to Redis queue "agent.tasks"
   ▼
Orchestrator.run(envelope)
   classify → plan → loop { select tool → ToolGateway → observe → evaluate } → review → finish
   │  every transition → signed callback POST /internal/agent/events
   ▼
Laravel persists agent_runs / agent_steps / agent_tool_calls, broadcasts realtime events
```

**TaskEnvelope** (no secrets): task id, type, organization/project ids, project context
(stack, AI notes, environments, repositories), policy snapshot, budgets, and a short-lived
credential *handle* the Tool Gateway can redeem for scoped access.

## Task states

`PENDING → PLANNING → INVESTIGATING → EXECUTING → TESTING → REVIEWING → WAITING_APPROVAL →
DEPLOYING → MONITORING → COMPLETED`, with `FAILED`, `CANCELLED` and `REQUIRES_HUMAN` reachable
from any active state. A task has one or more runs; a run has ordered steps; a step has tool calls.

## Budgets

| Budget | Default | On exhaustion |
|---|---|---|
| `max_steps` | 40 | `REQUIRES_HUMAN` |
| `max_tool_calls` | 100 | `REQUIRES_HUMAN` |
| `max_retries` (per failing action) | 3 | try alternative, then `REQUIRES_HUMAN` |
| `max_runtime_seconds` | 1800 | suspend, notify |
| `max_tokens`, `max_cost` | per organization | suspend, notify |

## Interfaces

```python
class LLMProvider(Protocol):
    async def complete(self, messages: list[Message], tools: list[ToolSpec], budget: Budget) -> Completion: ...
# AnthropicProvider, OpenAIProvider, GeminiProvider, LocalProvider

class Agent(Protocol):
    name: str
    allowed_tools: set[str]
    async def run(self, ctx: RunContext) -> AgentResult: ...   # result carries confidence 0..1
# CodeAgent, DebugAgent, DatabaseAgent, DevOpsAgent, SecurityAgent, ReviewerAgent
```

Agents depend only on `LLMProvider`, never on a vendor SDK.

## Agents

| Agent | Responsibility | Typical tools |
|---|---|---|
| CodeAgent | understand architecture, change code, write tests, branch/commit/PR | read_file, search_code, modify_file, run_test, create_branch, commit, create_pull_request |
| DebugAgent | logs → stack trace → code → root cause → fix proposal | read_log, search_code, read_file, db_read_schema |
| DatabaseAgent | schema, slow queries, missing indexes, migrations | db_read_schema, db_explain, create_migration (approval) |
| DevOpsAgent | containers, resources, deploy, rollback | docker_ps, docker_logs, server_status, deploy_staging, rollback |
| SecurityAgent | dependency CVEs, secret scan, injection/XSS/authz review | dependency_audit, secret_scan, sast_scan |
| ReviewerAgent | final diff review, test/security gate before PR | read_diff, run_test |

## Memory

* **Project memory:** curated facts (`projects.ai_context`), editable by humans.
* **Task memory:** plan, steps, observations, errors and solutions of the task.
* **Repository knowledge:** symbol index (files, classes, functions, routes, models, migrations,
  components) + embeddings behind a `Retriever` interface: `semantic_search`, `code_search`,
  `symbol_search`, `documentation_search`, `incident_search`. Context is retrieved, never the whole repository.

## Failure handling

```text
tool failure → classify (transient / input / permission / environment)
  transient → retry with backoff (≤ max_retries)
  input     → re-plan with the error as observation
  permission/denied → stop that path, never retry, record
  still failing → REQUIRES_HUMAN with a summary of attempts
```
