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

# Queue worker for TriageMessage jobs. It runs in this same container because
# the SQLite database (which also holds the job queue) can't be shared with a
# separate worker service. It runs as www-data so files it creates stay
# writable by Apache, and restarts automatically if it exits.
# --timeout must match TriageMessage::$timeout and stay below the queue's
# retry_after (240s), or a slow AI call could be picked up twice.
if [ "${RUN_QUEUE_WORKER:-true}" = "true" ]; then
    (
        while true; do
            su -s /bin/sh -c "php artisan queue:work database --sleep=3 --tries=1 --timeout=180 --max-time=3600" www-data || true
            echo "Queue worker exited; restarting in 2s." >&2
            sleep 2
        done
    ) &
fi

exec "$@"
