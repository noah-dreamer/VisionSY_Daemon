#!/bin/bash
set -euo pipefail
# CLI overrides (php artisan, backups) must never start a second database instance.
if [ "${1:-}" != supervisord ]; then
    exec docker-php-entrypoint "$@"
fi
: "${APP_KEY:?APP_KEY is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"
: "${MARIADB_ROOT_PASSWORD:?MARIADB_ROOT_PASSWORD is required}"
mkdir -p /run/visionsy /run/mysqld /app/storage/app/public \
    /app/storage/framework/{cache/data,sessions,views} /app/storage/logs /app/bootstrap/cache
chown mysql:mysql /run/mysqld
rm -f /run/visionsy/ready
exec "$@"
