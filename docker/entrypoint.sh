#!/bin/sh
# Runs before php-fpm, the scheduler or the queue worker. Config is cached from the container's
# environment on every start, so changing back/.env only needs `docker compose up -d`.
set -e

if [ -z "$APP_KEY" ]; then
  echo "APP_KEY is empty. Generate one with: docker compose run --rm api php artisan key:generate --show" >&2
  exit 1
fi

# storage is a volume; make sure Laravel's folders exist in it
mkdir -p storage/app/public storage/app/private storage/framework/cache storage/framework/sessions \
  storage/framework/views storage/logs

php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan storage:link --force >/dev/null 2>&1 || true

# Only the api service migrates, so scheduler and queue never run migrations at the same time.
if [ "$RUN_MIGRATIONS" = "1" ]; then
  php artisan migrate --force
  # Roles and permissions only; never the full DatabaseSeeder (it creates demo accounts).
  php artisan db:seed --class=RoleSeeder --force
fi

exec "$@"
