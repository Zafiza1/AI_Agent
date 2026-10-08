#!/bin/sh
# Prepares the Laravel app for development containers, then runs the given command.
#   RUN_MIGRATIONS=true  run migrations (only the "backend" service sets this)
#   SEED_ON_START=true   seed development data when the database has no users
set -e

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] installing composer dependencies"
    composer install --no-interaction --prefer-dist --no-progress
fi

if [ ! -f .env ]; then
    echo "[entrypoint] creating .env from .env.example"
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    echo "[entrypoint] generating APP_KEY"
    php artisan key:generate --force --no-interaction
fi

echo "[entrypoint] waiting for postgres at ${DB_HOST:-postgres}:${DB_PORT:-5432}"
i=0
until php -r 'exit(@fsockopen(getenv("DB_HOST") ?: "postgres", (int) (getenv("DB_PORT") ?: 5432)) ? 0 : 1);'; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
        echo "[entrypoint] postgres did not become reachable" >&2
        exit 1
    fi
    sleep 1
done

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction

    if [ "${SEED_ON_START:-false}" = "true" ] && [ "$(php artisan tinker --execute='echo \App\Models\User::count();' 2>/dev/null | tail -n1)" = "0" ]; then
        echo "[entrypoint] seeding development data"
        php artisan db:seed --force --no-interaction
    fi
fi

exec "$@"
