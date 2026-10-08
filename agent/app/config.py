from functools import lru_cache

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Agent engine configuration, read from environment variables (prefix AGENT_)."""

    model_config = SettingsConfigDict(env_prefix="AGENT_", env_file=".env", extra="ignore")

    env: str = "local"
    log_level: str = "INFO"

    # URL of the Laravel control plane, used for progress callbacks (Phase 3).
    control_plane_url: str = "http://backend:8000"

    # Shared secret for control plane <-> agent calls (Phase 3). Never logged.
    internal_token: str = Field(default="", repr=False)

    redis_url: str = "redis://redis:6379/1"

    # Default execution budgets; per-organization policy may lower them.
    max_steps: int = 40
    max_tool_calls: int = 100
    max_retries: int = 3
    max_runtime_seconds: int = 1800


@lru_cache
def get_settings() -> Settings:
    return Settings()
