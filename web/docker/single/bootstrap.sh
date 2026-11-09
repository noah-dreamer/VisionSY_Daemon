#!/bin/bash
set -euo pipefail
cd /app
# A persistent bootstrap_cache volume may still contain the previous deployment's DB_HOST.
php artisan config:clear
for attempt in {1..180}; do
    if php -r 'new PDO("mysql:host=127.0.0.1;port=3306;dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' 2>/dev/null; then
        break
    fi
    if [ "$attempt" = 180 ]; then
        echo '[startup] Database connection timed out.' >&2
        exit 1
    fi
    sleep 1
done
# The only migration runner; no workers/web until the entire schema is ready.
php artisan migrate --force
php artisan optimize:clear
supervisorctl -c /etc/visionsy/supervisord.conf start application:*
touch /run/visionsy/ready
