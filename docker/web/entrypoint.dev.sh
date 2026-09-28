#!/bin/sh
set -e

# Reverb's keys, before any artisan command: the broadcast channels are built
# when the app boots, so every PHP process from here on must see the same keys.
. /usr/local/bin/reverb.sh

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

# PHP deps live in the bind-mounted source, so they cannot be baked into the
# image — anything installed at build time would sit under the mount, unseen.
# Installed here instead, on start: when vendor/ is missing, and again whenever
# composer.lock has changed since the last install (a pulled branch that adds a
# package), marked by a hash in vendor/.retrobite-lock. Otherwise skipped, so a
# plain restart does not spend a composer run on nothing.
_composer_hash=$(sha1sum /app/composer.lock 2>/dev/null | cut -d' ' -f1)
if [ ! -f /app/vendor/autoload.php ] || [ "$(cat /app/vendor/.retrobite-lock 2>/dev/null)" != "$_composer_hash" ]; then
    echo "Installing PHP dependencies ..."
    composer install --no-interaction --working-dir=/app
    echo "$_composer_hash" > /app/vendor/.retrobite-lock
fi

# Node deps go into the container's own node_modules volume, not the host's:
# package.json pins linux-x64-gnu binaries and this is musl, so the two trees
# cannot be shared. This container is the one place node runs — use
# ./retrobite npm, not npm on the host — so it keeps its tree in step with the lock
# file: installed when empty, and again whenever package-lock.json has changed
# since the last install (a pulled branch that adds a package). Otherwise
# skipped, since npm ci would throw the whole thing away on every restart.
_node_installed=
_lock_hash=$(sha1sum /app/package-lock.json 2>/dev/null | cut -d' ' -f1)
if [ ! -d /app/node_modules/vite ] || [ "$(cat /app/node_modules/.retrobite-lock 2>/dev/null)" != "$_lock_hash" ]; then
    echo "Installing node dependencies ..."
    npm install --prefix /app --no-audit --no-fund
    echo "$_lock_hash" > /app/node_modules/.retrobite-lock
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
    chown -R "$WEB_USER:$WEB_GROUP" /app/node_modules \
        || echo "WARNING: could not take ownership of node_modules; Vite may fail to start." >&2
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

# After migrate, which runs as root and would otherwise leave root-owned files.
#
# Never the games tree: ROM files are read and never written, ownership
# included, and it is a bind mount that can be tens of thousands of files on a
# slow or external disk — walking it on every boot is what made a start hang
# here for minutes — or a network mount (sshfs, NFS) that refuses ownership
# changes outright. Everything else only where the owner is wrong, so after the
# first boot this is a quick walk that writes nothing, rather than a chown of
# every downloaded image and thumbnail.
#
# Never fatal either: a tree whose ownership cannot be set still works when
# user-setup.sh matched the ids, and a boot that dies here takes the whole web
# container down with it.
find /app/storage /app/bootstrap/cache \
    -path /app/storage/app/games -prune -o \
    \( ! -user "$WEB_USER" -o ! -group "$WEB_GROUP" \) -exec chown -h "$WEB_USER:$WEB_GROUP" {} + \
    || echo "WARNING: could not hand every file under storage/ to $WEB_USER; carrying on." >&2

# Live updates, as in production (keys were set at the top, before anything
# ran PHP). --debug prints each connection and message, which is the whole
# point of running it here.
start_reverb --debug

# queue:listen rather than queue:work, which is the whole difference here.
# work keeps one booted application in memory for its whole life, so a job runs
# whatever the code was when the worker started — edit a job class and the
# container happily keeps running the old one, with nothing to say so. listen
# boots a fresh application per job, which costs a little time and is exactly
# the right trade while the source is bind-mounted.
#
# How many of each comes from the same variables as the production
# entrypoint — see there, and .env.example, for why each has its default.
#
# Every listener carries --timeout, a little over the longest job on its
# queues, because listen kills its child after 60 seconds whatever the job's
# own $timeout says — and a killed child takes the listener down with it. The
# restart loop in queue-workers.sh brings it back, but the job still never
# finishes. The longest on each: MatchGame 120 (scraper), ScanConsoleFolder
# and WriteConsoleExports 1800 (media, default), SyncHashIndex 900 (ra),
# ReconcileProgress 300 (ra-progress), HashFile 3600 (hash), FileTransferJob
# 3600 (transfer). Raise the number
# here when one of those grows.
. /usr/local/bin/queue-workers.sh

workers QUEUE_WORKERS_SCRAPER 1 php /app/artisan queue:listen \
    --queue=scraper --sleep=3 --tries=3 --timeout=150

workers QUEUE_WORKERS_MEDIA 1 php /app/artisan queue:listen \
    --queue=media,default --sleep=3 --tries=3 --timeout=1860

# Cover thumbnails: CPU work, on a queue of its own so a library's backfill
# runs beside the downloads instead of in front of them.
workers QUEUE_WORKERS_THUMBNAILS 1 php /app/artisan queue:listen \
    --queue=thumbnails --sleep=3 --tries=3

# The console toolbox: an export's 1800 plus the margin the others carry.
workers QUEUE_WORKERS_TOOLBOX 1 php /app/artisan queue:listen \
    --queue=toolbox --sleep=3 --tries=3 --timeout=1860

# RetroAchievements. Note that queue:listen ignores retry_after entirely — it
# reboots per job and goes by --timeout — so the long-connection split that
# matters in production is invisible here. Worth knowing before concluding from
# a working dev container that the production one is fine.
workers QUEUE_WORKERS_RA 1 php /app/artisan queue:listen \
    --queue=ra --sleep=3 --tries=3 --timeout=960

workers QUEUE_WORKERS_RA_PROGRESS 1 php /app/artisan queue:listen \
    --queue=ra-progress --sleep=3 --tries=3 --timeout=360

workers QUEUE_WORKERS_HASH 1 php /app/artisan queue:listen database-long \
    --queue=hash,ra-hash --sleep=3 --tries=3 --timeout=3660

# Copies to network shares, on the long connection for the same reason as
# hashing: a disc image over a slow link outlasts any short retry_after.
# Three, because a copy spends its time waiting on the share's answers (tens of
# milliseconds each, several per file) rather than on bandwidth, and a console
# sent at once is a job per game: three games in flight go about three times
# as fast. The game list is written under a lock, so two sends to one box do
# not lose each other's entries.
workers QUEUE_WORKERS_TRANSFER 3 php /app/artisan queue:listen database-long \
    --queue=transfer --sleep=3 --tries=2 --timeout=3660

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
