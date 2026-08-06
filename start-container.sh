#!/bin/bash

# Custom Railpack (Coolify) startup: a copy of the PHP provider's default
# script, extended to run the queue worker and scheduler in the same container.
# Upstream template:
# https://github.com/railwayapp/railpack/blob/main/core/providers/php/start-container.sh

set -e

# When /app/storage is mounted as an (initially empty) volume,
# the framework directories don't exist yet.
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

# The SQLite file must live on the persistent volume (DB_DATABASE in env).
if [ "$DB_CONNECTION" = "sqlite" ] && [ -n "$DB_DATABASE" ] && [ ! -f "$DB_DATABASE" ]; then
  mkdir -p "$(dirname "$DB_DATABASE")"
  touch "$DB_DATABASE"
fi

if [ "$RAILPACK_SKIP_MIGRATIONS" != "true" ]; then
  echo "Running migrations ..."
  php artisan migrate --force
fi

php artisan storage:link
php artisan optimize:clear
php artisan optimize

# Queue worker, restarted after a crash; DB_QUEUE_RETRY_AFTER must stay > --timeout.
(
  while true; do
    php artisan queue:work --tries=5 --timeout=300 --sleep=3 || true
    sleep 1
  done
) &

# Scheduler (daily recordings:prune)
php artisan schedule:work &

echo "Starting Laravel server ..."
docker-php-entrypoint --config /Caddyfile --adapter caddyfile 2>&1
