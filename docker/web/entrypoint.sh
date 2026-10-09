#!/bin/sh
set -e

# The application key, then Reverb's, before any artisan command. Both are
# generated once into storage/state when the environment sets none.
. /usr/local/bin/app-key.sh

# Reverb's keys, before any artisan command: the broadcast channels are built
# when the app boots, so every PHP process from here on must see the same keys.
. /usr/local/bin/reverb.sh

# What hands each long-running process to supervisord (Reverb, the scheduler,
# the queue workers). They are written down here and started at the very end.
. /usr/local/bin/supervise.sh

GAMES_DIR=/app/storage/app/games

. /usr/local/bin/warnings.sh

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

# After migrate, which runs as root and would otherwise leave a root-owned
# laravel.log behind. bootstrap/cache is where Laravel writes packages.php,
# services.php and the config/route caches — root-owned it throws
# "must be present and writable".
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

# Live updates (keys were set at the top, before anything ran PHP).
start_reverb

# Conversions, before any worker can pick a conversion up: a conversion the
# database still says is running was interrupted by whatever stopped this
# container, so it is marked failed and its half-written output removed. Then
# which conversion tools are here, into the log. Neither may stop the boot.
su-exec "$WEB_USER" php /app/artisan conversion:recover \
    || echo "WARNING: could not check for interrupted conversions; carrying on." >&2
su-exec "$WEB_USER" php /app/artisan conversion:tools \
    || echo "WARNING: could not check the conversion tools; carrying on." >&2

# Queue workers, split by what actually limits them. One per queue unless
# .env says otherwise (see docs/configuration.md): the smallest footprint that still
# works every queue, on hardware nobody here has measured.
#
# More scraper workers add speed up to the threads the ScreenScraper account
# has (1 on a plain account, 6 on Gold; Settings → ScreenScraper shows it).
# ScreenScraperService::paced() holds one slot per thread, so workers past
# that wait for a free one rather than earning HTTP 429.
# Media downloads and checksumming are bound by disk and bandwidth instead, and
# are the ones worth raising on a machine that has the room. --max-time
# recycles a worker hourly, which is the ordinary guard against a long-lived
# PHP process accumulating memory.
#
# Under supervisord, which starts each one again when it exits: the hourly
# --max-time exit and a job killed at its timeout would otherwise end that
# worker for the life of the container.
#
# No --timeout here: queue:work takes each job's own $timeout, and every job
# declares one. What has to follow them is the connection's retry_after — see
# config/queue.php.
. /usr/local/bin/queue-workers.sh

workers QUEUE_WORKERS_SCRAPER 1 php /app/artisan queue:work \
    --queue=scraper --sleep=3 --tries=3 --max-time=3600

# Artwork is ScreenScraper traffic too, through the same thread slots as the
# scraper, so its workers share the account's max_threads with the scraper's.
# A queue of its own, not shared with default: listening to media,default,
# a library's worth of artwork held every scan and file count back until the
# last download was done.
workers QUEUE_WORKERS_MEDIA 1 php /app/artisan queue:work \
    --queue=media --sleep=3 --tries=3 --max-time=3600

# Scans and file counts, on their own worker so they never wait for artwork.
workers QUEUE_WORKERS_DEFAULT 1 php /app/artisan queue:work \
    --queue=default --sleep=3 --tries=3 --max-time=3600

# Cover thumbnails: CPU work, on a queue of its own so a library's backfill
# runs beside the downloads instead of in front of them.
workers QUEUE_WORKERS_THUMBNAILS 1 php /app/artisan queue:work \
    --queue=thumbnails --sleep=3 --tries=3 --max-time=3600

# The console toolbox: loader exports and license ID reading, on a queue of
# their own so pressing Write OPL art never waits behind a library's artwork.
# Still on the long connection, so a conversion queued here before conversions
# had a queue of their own is not handed out twice while it runs.
workers QUEUE_WORKERS_TOOLBOX 1 php /app/artisan queue:work database-long \
    --queue=toolbox --sleep=3 --tries=3 --max-time=3600

# Format conversions, apart from the toolbox so an hour-long disc never holds
# an export back. On the long connection because a conversion runs for up to
# CONVERSION_TIMEOUT seconds, past the default connection's half-hour
# retry_after, after which a second worker would start it again.
workers QUEUE_WORKERS_CONVERSION 1 php /app/artisan queue:work database-long \
    --queue=toolbox-conversion --sleep=3 --tries=3 --max-time=3600

# RetroAchievements: identification and set downloads are HTTP and quick, so
# one worker keeps up; progress gets its own so a library-wide backfill of the
# first two cannot starve it.
workers QUEUE_WORKERS_RA 1 php /app/artisan queue:work \
    --queue=ra --sleep=3 --tries=3 --max-time=3600

workers QUEUE_WORKERS_RA_PROGRESS 1 php /app/artisan queue:work \
    --queue=ra-progress --sleep=3 --tries=3 --max-time=3600

# The long connection, and the connection is a positional argument rather than
# a flag: `queue:work --queue=ra-hash` would quietly run on the default
# connection and put its 90-second retry_after back. The jobs table has no
# connection column, so queue names are the only isolation there is — which is
# why the checksum queue is named `hash` and not left on `media`.
#
# Hashing is disk and CPU bound on one machine, so past two or so more
# processes mostly means more seeking.
workers QUEUE_WORKERS_HASH 1 php /app/artisan queue:work database-long \
    --queue=hash,ra-hash --sleep=3 --tries=3 --timeout=3600 --max-time=3600

# Copies to network shares, on the long connection for the same reason as
# hashing: a disc image over a slow link outlasts any short retry_after.
# Three, because a copy spends its time waiting on the share's answers (tens of
# milliseconds each, several per file) rather than on bandwidth, and a console
# sent at once is a job per game: three games in flight go about three times
# as fast. The game list is written under a lock, so two sends to one box do
# not lose each other's entries.
workers QUEUE_WORKERS_TRANSFER 3 php /app/artisan queue:work database-long \
    --queue=transfer --sleep=3 --tries=2 --timeout=3600 --max-time=3600

# The scheduler, for the nightly index sync and the progress pulse.
supervise scheduler 1 php /app/artisan schedule:work

# supervisord as PID 1 from here: nginx, PHP-FPM, and everything handed to it
# above, each started again when it exits (docker/web/supervisord.conf).
exec supervisord -c /etc/supervisord.conf
