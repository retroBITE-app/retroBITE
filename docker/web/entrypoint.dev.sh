#!/bin/sh
set -e

# Ensure storage subdirs exist
mkdir -p /app/web/storage/games /app/web/storage/tmp /app/web/database

# Install PHP deps (volume-mounted, so not baked into image)
composer install --no-interaction --working-dir=/app/web

# Ensure PHP-FPM (www-data) can write to storage and database dirs
chown -R www-data:www-data /app/web/storage /app/web/database /data

# Start PHP-FPM in the background (manages its own worker pool)
php-fpm -D

# Run Nginx in the foreground so it becomes PID 1 and Docker tracks it
exec nginx -g 'daemon off;'
