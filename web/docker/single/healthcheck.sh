#!/bin/bash
set -euo pipefail
[ -f /run/visionsy/ready ]
for service in database application:web application:worker application:scheduler; do
    supervisorctl -c /etc/visionsy/supervisord.conf status "$service" | grep -q 'RUNNING'
done
php -r 'new PDO("mysql:host=127.0.0.1;port=3306;dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));'
curl -fsS --max-time 3 http://127.0.0.1:80/up >/dev/null
