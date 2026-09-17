#!/bin/sh
set -e

# Ensure storage subdirs exist
mkdir -p /app/storage/app/games \
         /app/storage/app/docs \
         /app/storage/framework/cache/data \
         /app/storage/framework/sessions \
         /app/storage/framework/views \
         /app/storage/logs \
         /app/bootstrap/cache

# Sets WEB_USER / WEB_GROUP from the owner of the bind-mounted source tree.
. /usr/local/bin/user-setup.sh /app

# Remove any stale production build so Vite's dev server is used instead.
# Otherwise Laravel finds public/build/manifest.json and serves the old bundle,
# ignoring the dev server entirely.
rm -rf /app/public/build

# Install PHP deps (volume-mounted, so not baked into the image)
composer install --no-interaction --working-dir=/app

# The DB lives on the /data volume so it survives `docker compose down`.
mkdir -p /data
touch /data/database.sqlite

php /app/artisan migrate --force

# Guarantee a login exists on a fresh install. No-ops once any user exists.
php /app/artisan db:seed --class=DefaultUserSeeder --force

# After migrate, which runs as root and would otherwise leave root-owned files.
chown -R "$WEB_USER:$WEB_GROUP" /app/storage /app/bootstrap/cache /data

# Start PHP-FPM in the background (manages its own worker pool)
php-fpm -D

# Run Nginx in the foreground so it becomes PID 1 and Docker tracks it
exec nginx -g 'daemon off;'
