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

# Node deps go into the container's own node_modules volume, not the host's:
# package.json pins linux-x64-gnu binaries and this is musl, so the two trees
# cannot be shared. Skipped when it is already populated, since npm ci would
# throw the whole thing away on every restart.
_node_installed=
if [ ! -d /app/node_modules/vite ]; then
    echo "Installing node dependencies ..."
    npm install --prefix /app --no-audit --no-fund
    _node_installed=1
fi

# Docker creates the node_modules volume owned by root, and the install above
# runs as root too — but Vite is started as WEB_USER further down, and the
# first thing it wants is to mkdir node_modules/.vite. Without this it dies on
# startup with EACCES while nginx and php-fpm come up perfectly, so the
# container looks healthy and the frontend simply serves nothing.
#
# Not folded into the branch above: the volume outlives the container, so the
# usual case is a tree left root-owned by an earlier run that this boot has no
# reason to reinstall.
#
# Guarded on whether WEB_USER can actually write there, which is the condition
# that fails — a populated node_modules is too large to walk on every boot for
# nothing.
if [ -n "$_node_installed" ] || ! su-exec "$WEB_USER" test -w /app/node_modules; then
    echo "Taking ownership of node_modules ..."
    chown -R "$WEB_USER:$WEB_GROUP" /app/node_modules
fi

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

# After migrate, which runs as root and would otherwise leave root-owned files.
chown -R "$WEB_USER:$WEB_GROUP" /app/storage /app/bootstrap/cache

# queue:listen rather than queue:work, which is the whole difference here.
# work keeps one booted application in memory for its whole life, so a job runs
# whatever the code was when the worker started — edit a job class and the
# container happily keeps running the old one, with nothing to say so. listen
# boots a fresh application per job, which costs a little time and is exactly
# the right trade while the source is bind-mounted.
#
# One for scraping, because ScreenScraper allows a plain account a single
# thread; a few for media and hashing, which are disk-bound instead.
#
# --timeout because listen kills its child after 60 seconds whatever the job
# says, and MatchGame allows itself 120 for a slow provider.
su-exec "$WEB_USER" php /app/artisan queue:listen \
    --queue=scraper --sleep=3 --tries=3 --timeout=150 &

for _ in 1 2 3; do
    su-exec "$WEB_USER" php /app/artisan queue:listen \
        --queue=media,default --sleep=3 --tries=3 &
done

# RetroAchievements. Note that queue:listen ignores retry_after entirely — it
# reboots per job and goes by --timeout — so the long-connection split that
# matters in production is invisible here. Worth knowing before concluding from
# a working dev container that the production one is fine.
su-exec "$WEB_USER" php /app/artisan queue:listen \
    --queue=ra --sleep=3 --tries=3 &

su-exec "$WEB_USER" php /app/artisan queue:listen \
    --queue=ra-progress --sleep=3 --tries=3 &

for _ in 1 2; do
    su-exec "$WEB_USER" php /app/artisan queue:listen database-long \
        --queue=hash,ra-hash --sleep=3 --tries=3 --timeout=3600 &
done

su-exec "$WEB_USER" php /app/artisan schedule:work &

# Vite, in here rather than on the host, so working on the frontend needs
# nothing installed locally. Starting it writes public/hot, which Laravel reads
# to point asset URLs at the dev server instead of the built bundle — which is
# also why the built bundle was removed above.
su-exec "$WEB_USER" npm run dev --prefix /app &

# Start PHP-FPM in the background (manages its own worker pool)
php-fpm -D

# Run Nginx in the foreground so it becomes PID 1 and Docker tracks it
exec nginx -g 'daemon off;'
