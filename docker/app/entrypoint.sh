#!/bin/bash
set -euo pipefail
cd /var/www/html

# Cache config and routes at start-up (env vars come from docker compose at runtime).
php artisan config:cache >/dev/null
php artisan route:cache >/dev/null

# Wait for MySQL, then apply migrations.
for i in $(seq 1 60); do
  if php artisan migrate --force --no-interaction; then break; fi
  echo "waiting for database ($i)..."; sleep 2
  if [ "$i" = 60 ]; then echo "database never became ready"; exit 1; fi
done

php-fpm -F &
FPM_PID=$!
nginx -g 'daemon off;' &
NGINX_PID=$!

graceful_stop() {
  echo "graceful shutdown: draining nginx, then php-fpm"
  kill -QUIT "$NGINX_PID" 2>/dev/null || true
  wait "$NGINX_PID" 2>/dev/null || true
  kill -QUIT "$FPM_PID" 2>/dev/null || true
  wait "$FPM_PID" 2>/dev/null || true
  exit 0
}
trap graceful_stop QUIT TERM INT

echo "wallet ledger API listening on :8080"
# Exit (and let compose restart/report) if either process dies.
wait -n "$FPM_PID" "$NGINX_PID"
graceful_stop
