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

# MariaDB accepts connections a good while after the container reports
# started, and migrate does not retry — without this the first boot of a fresh
# stack races the database and dies.
echo "Waiting for ${DB_HOST:-retrobite-db}:${DB_PORT:-3306} ..."
i=0
until php -r 'exit(@fsockopen(getenv("DB_HOST") ?: "retrobite-db", (int)(getenv("DB_PORT") ?: 3306), $e, $s, 2) ? 0 : 1);'; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
        echo "Database never came up after 120s; giving up." >&2
        exit 1
    fi
    sleep 2
done

# Bring the schema up to date before serving any request. --force because
# migrate prompts for confirmation when APP_ENV=production.
php /app/artisan migrate --force

# Guarantee a login exists on a fresh install. No-ops once any user exists.
php /app/artisan db:seed --class=DefaultUserSeeder --force

# After migrate, which runs as root and would otherwise leave a root-owned
# laravel.log behind. bootstrap/cache is where Laravel writes packages.php,
# services.php and the config/route caches — root-owned it throws
# "must be present and writable".
chown -R "$WEB_USER:$WEB_GROUP" /app/storage /app/bootstrap/cache

# Queue workers, split by what actually limits them.
#
# The scraper queue runs exactly one worker because ScreenScraper allows a
# plain account one thread: a second worker would earn HTTP 429, not speed.
# Media downloads and checksumming are bound by disk and bandwidth instead, so
# those get a few. --max-time recycles a worker hourly, which is the ordinary
# guard against a long-lived PHP process accumulating memory.
#
# Backgrounded rather than run under a supervisor: nginx is PID 1 and the
# container is restarted as a unit, so a dead worker is a restarted container.
su-exec "$WEB_USER" php /app/artisan queue:work \
    --queue=scraper --sleep=3 --tries=3 --max-time=3600 &

for _ in 1 2 3; do
    su-exec "$WEB_USER" php /app/artisan queue:work \
        --queue=media,default --sleep=3 --tries=3 --max-time=3600 &
done

# Start PHP-FPM in the background (manages its own worker pool)
php-fpm -D

# Run Nginx in the foreground so it becomes PID 1 and Docker tracks it
exec nginx -g 'daemon off;'
