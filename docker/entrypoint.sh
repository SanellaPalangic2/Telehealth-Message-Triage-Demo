#!/bin/sh
# Container start-up for Triage Desk (used by the Dockerfile).
set -e
cd /var/www/html

# Never serve Laravel's debug pages in production: they can expose
# environment details. Refuse to start rather than risk it.
if [ "$APP_ENV" = "production" ] && [ "$APP_DEBUG" = "true" ]; then
    echo "Refusing to start: APP_DEBUG=true in production." >&2
    exit 1
fi

if [ -z "$APP_KEY" ]; then
    echo "WARNING: APP_KEY is not set. Using a temporary key; sessions reset on every restart." >&2
    echo "         Set one with the output of: php artisan key:generate --show" >&2
    APP_KEY="$(php artisan key:generate --show --no-ansi)"
    export APP_KEY
fi

if [ -z "$ANTHROPIC_API_KEY" ]; then
    echo "WARNING: ANTHROPIC_API_KEY is not set. Rules and review work; AI triage will show a clear error." >&2
fi

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
touch database/database.sqlite

php artisan migrate --force --no-interaction
# Seeds the 30 synthetic messages only if the database is empty.
php artisan db:seed --force --no-interaction

php artisan config:cache
php artisan route:cache
php artisan view:cache

chown -R www-data:www-data storage bootstrap/cache database

exec "$@"
