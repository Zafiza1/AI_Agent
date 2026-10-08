#!/bin/sh
# Runs every check that CI runs, locally. Usage: scripts/test-all.sh
set -e

ROOT=$(cd "$(dirname "$0")/.." && pwd)

echo "==> backend"
cd "$ROOT/backend"
php artisan test
vendor/bin/pint --test

echo "==> frontend"
cd "$ROOT/frontend"
npm run lint
npm run build

echo "==> agent"
cd "$ROOT/agent"
python -m pytest -q
python -m ruff check .

echo "All checks passed."
