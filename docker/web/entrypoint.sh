#!/bin/sh
set -e

# Match www-data UID/GID to host user so bind-mounted files just work
HOST_UID=$(stat -c '%u' /games 2>/dev/null || echo "0")
HOST_GID=$(stat -c '%g' /games 2>/dev/null || echo "0")
if [ "$HOST_UID" != "0" ]; then
    deluser www-data 2>/dev/null || true
    addgroup -g "$HOST_GID" -S www-data 2>/dev/null || true
    adduser -u "$HOST_UID" -G www-data -S -D -H www-data 2>/dev/null || true
fi
addgroup www-data users 2>/dev/null || true

# Ensure storage subdirs exist
mkdir -p /app/web/storage/games /app/web/storage/tmp

# Ensure PHP-FPM (www-data) can write to storage, database, and games dirs
chown -R www-data:www-data /app/web/storage /app/web/database /data

# Start PHP-FPM in the background (manages its own worker pool)
php-fpm -D

# Run Nginx in the foreground so it becomes PID 1 and Docker tracks it
exec nginx -g 'daemon off;'
