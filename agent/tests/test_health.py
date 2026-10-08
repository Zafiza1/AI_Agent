from fastapi.testclient import TestClient

from app.config import Settings
from app.main import app

client = TestClient(app)


def test_health_reports_service_and_budgets():
    response = client.get("/health")

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "ok"
    assert body["service"] == "agent"
    assert body["budgets"]["max_retries"] == 3


def test_internal_token_is_not_exposed_in_repr():
    settings = Settings(internal_token="very-secret")

    assert "very-secret" not in repr(settings)
