#!/bin/sh
set -e

GAMES_DIR=/app/storage/app/games

# Ensure storage subdirs exist. The framework dirs are in .dockerignore, so the
# image ships the tree empty — Laravel does not create them itself and throws on
# the first request that writes a view cache or a session.
mkdir -p "$GAMES_DIR" \
         /app/storage/app/docs \
         /app/storage/framework/cache/data \
         /app/storage/framework/sessions \
         /app/storage/framework/views \
         /app/storage/logs

# Sets WEB_USER / WEB_GROUP from the owner of the bind-mounted games dir.
. /usr/local/bin/user-setup.sh "$GAMES_DIR"

# The DB lives on the /data volume, not in the image layer, so it survives
# `docker compose down`. DB_DATABASE is pointed here by compose. migrate creates
# the schema but not the file.
mkdir -p /data
touch /data/database.sqlite

# Bring the schema up to date before serving any request. --force because
# migrate prompts for confirmation when APP_ENV=production.
php /app/artisan migrate --force

# Guarantee a login exists on a fresh install. No-ops once any user exists.
php /app/artisan db:seed --class=DefaultUserSeeder --force

# After migrate, which runs as root and would otherwise leave a root-owned
# laravel.log behind. bootstrap/cache is where Laravel writes packages.php,
# services.php and the config/route caches — root-owned it throws
# "must be present and writable".
chown -R "$WEB_USER:$WEB_GROUP" /app/storage /app/bootstrap/cache /data

# Start PHP-FPM in the background (manages its own worker pool)
php-fpm -D

# Run Nginx in the foreground so it becomes PID 1 and Docker tracks it
exec nginx -g 'daemon off;'
