# Tools and the Tool Gateway (design — Phase 3+)

Agents never touch GitHub, servers or databases directly. Every action is a **tool call** through
the Tool Gateway.

## Tool interface

```python
class Tool(Protocol):
    def name(self) -> str: ...
    def description(self) -> str: ...
    def schema(self) -> dict: ...                 # JSON Schema for arguments
    def risk_level(self, args) -> RiskLevel: ...  # may depend on args (e.g. environment)
    def required_permission(self) -> str: ...
    def target_type(self) -> str: ...             # repository, sandbox, server, database, ...
    async def execute(self, args, ctx) -> ToolResult: ...
```

Families: `FileTool`, `GitTool`, `ShellTool` (sandbox only), `DockerTool`, `DatabaseTool`,
`ServerTool`, `DeploymentTool`, `MonitoringTool`, `SecurityTool`.

## Gateway pipeline

```text
1. resolve tool, validate args against schema
2. compute risk (static level, raised by environment: production ⇒ ≥ HIGH)
3. PolicyEngine.evaluate(org, user, agent, project, environment, tool, risk)
      DENY             → record denied call, return error to the agent
      REQUIRE_APPROVAL → create approval, set task WAITING_APPROVAL, suspend run
      ALLOW            → continue
4. execute inside the right boundary (sandbox container, provider API, server connector)
5. redact secrets from output, truncate large output, persist agent_tool_call
6. audit log entry (agent_id, tool, target, risk, approval_id, result)
```

## Catalogue and default risk

| Tool | Risk | Notes |
|---|---|---|
| read_file, list_files, search_code, read_log, run_test | LOW | sandbox / read-only |
| modify_file, create_file, delete_file | MEDIUM | sandbox only, never on a server |
| create_branch, commit, create_pull_request | MEDIUM | never the default branch |
| install_dependency | MEDIUM | sandbox, registry allow-list |
| docker_ps, docker_logs, server_status, cpu/memory/disk_usage | LOW | allow-listed commands |
| docker_restart (staging) | MEDIUM | |
| docker_restart (production) | HIGH | self-healing may pre-approve via policy |
| db_read_schema, db_explain, db_select (read replica) | LOW | statement classifier enforced |
| create_migration / run_migration | HIGH | approval |
| deploy_staging | MEDIUM | |
| deploy_production, rollback (production), production config | HIGH | approval |
| drop/truncate, destroy server, arbitrary prod shell | CRITICAL | denied |

## Policy engine examples

```text
CodeAgent  + read_file          + staging    = ALLOW
DevOpsAgent + deploy_production               = REQUIRE_APPROVAL
AnyAgent   + delete_production_database       = DENY
```

Organization policy may relax MEDIUM actions to automatic, never HIGH or CRITICAL.
