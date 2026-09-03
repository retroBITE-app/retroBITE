#!/bin/sh
set -e

# Match www-data UID/GID to host user so bind-mounted files just work
HOST_UID=$(stat -c '%u' /app/web)
HOST_GID=$(stat -c '%g' /app/web)
if [ "$HOST_UID" != "0" ]; then
    deluser www-data 2>/dev/null || true
    addgroup -g "$HOST_GID" -S www-data 2>/dev/null || true
    adduser -u "$HOST_UID" -G www-data -S -D -H www-data 2>/dev/null || true
fi
addgroup www-data users 2>/dev/null || true

# Ensure storage subdirs exist
mkdir -p /app/web/storage/games /app/web/storage/tmp /app/web/database

# Remove any stale production build so Vite.php falls back to the dev server
rm -rf /app/web/public/build

# Install PHP deps (volume-mounted, so not baked into image)
composer install --no-interaction --working-dir=/app/web

# Bring the schema up to date before serving any request. Runs as root, so the
# chown below has to follow it — the migration creates the SQLite file.
php /app/web/console migrate

# Ensure writable dirs are owned by www-data
chown -R www-data:www-data /app/web/storage /app/web/database /data

# Start PHP-FPM in the background (manages its own worker pool)
php-fpm -D

# Run Nginx in the foreground so it becomes PID 1 and Docker tracks it
exec nginx -g 'daemon off;'
