#!/bin/bash

# Vlastní startup pro Railpack (Coolify): kopie výchozího skriptu PHP provideru
# rozšířená o queue worker a scheduler ve stejném kontejneru.
# Upstream předloha:
# https://github.com/railwayapp/railpack/blob/main/core/providers/php/start-container.sh

set -e

# Když je /app/storage připojený jako (zpočátku prázdný) volume,
# framework adresáře ještě neexistují.
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

# SQLite soubor musí ležet na persistentním volume (DB_DATABASE v env).
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

# Queue worker s restartem po pádu; DB_QUEUE_RETRY_AFTER musí zůstat > --timeout.
(
  while true; do
    php artisan queue:work --tries=5 --timeout=300 --sleep=3 || true
    sleep 1
  done
) &

# Scheduler (denní recordings:prune)
php artisan schedule:work &

echo "Starting Laravel server ..."
docker-php-entrypoint --config /Caddyfile --adapter caddyfile 2>&1
