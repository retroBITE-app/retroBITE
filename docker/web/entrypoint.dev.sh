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

# MariaDB accepts connections well after its container reports started, and
# migrate does not retry.
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

php /app/artisan migrate --force

# Guarantee a login exists on a fresh install. No-ops once any user exists.
php /app/artisan db:seed --class=DefaultUserSeeder --force

# Idempotent: firstOrCreate per type, so a media type added in a release
# reaches an existing install without anyone remembering to seed it.
php /app/artisan db:seed --class=MediaTypePreferenceSeeder --force

# After migrate, which runs as root and would otherwise leave root-owned files.
chown -R "$WEB_USER:$WEB_GROUP" /app/storage /app/bootstrap/cache

# Queue workers. One for scraping, because ScreenScraper allows a plain account
# a single thread; a few for media and hashing, which are disk-bound instead.
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
