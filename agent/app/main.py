"""Agent engine entrypoint.

Phase 1 ships the service boundary only: configuration and a health endpoint used
by the control plane's system health check. Orchestration, LLM providers, the tool
gateway and the policy engine are implemented in Phase 3 (see docs/agent-architecture.md).
"""

import logging

from fastapi import FastAPI

from app import __version__
from app.config import get_settings

settings = get_settings()
logging.basicConfig(level=settings.log_level)

app = FastAPI(
    title="AI Maintenance Platform - Agent Engine",
    version=__version__,
    docs_url="/docs" if settings.env != "production" else None,
    redoc_url=None,
)


@app.get("/health", tags=["system"])
def health() -> dict[str, object]:
    return {
        "status": "ok",
        "service": "agent",
        "version": __version__,
        "budgets": {
            "max_steps": settings.max_steps,
            "max_tool_calls": settings.max_tool_calls,
            "max_retries": settings.max_retries,
            "max_runtime_seconds": settings.max_runtime_seconds,
        },
    }
