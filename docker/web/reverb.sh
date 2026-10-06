#!/bin/sh
# Live updates: Laravel Reverb, inside the web container.
#
# Sourced, not executed, by both entrypoints, before anything that runs PHP
# is started — php-fpm, the queue workers and Reverb itself all inherit the
# environment set here (php-fpm through clear_env = no in zz-env.conf).
#
# Keys. Taken from .env when it sets them; otherwise generated once and kept in
# storage/state/reverb.json (a volume, beside app.key), which config/broadcasting.php and
# config/reverb.php read when the environment has none. A file rather than only
# an export, because `docker exec … php artisan` starts outside this process
# tree and would otherwise see no keys and send nothing. Kept across restarts,
# because an open tab holds the key from the page it loaded and reconnects with
# it after the container comes back. Nothing bakes them into the image or the
# JS bundle; the browser reads the key from a <meta> tag.
#
# Reverb listens on 127.0.0.1:8080 only. nginx proxies /app/ (the websocket)
# to it and nothing else; the publishing API under /apps/ is reachable from
# inside the container alone. Plain http throughout: the project has no
# certificate. See docs/adr/0002-live-updates-over-reverb.md.

_random() {
    tr -dc 'a-z0-9' < /dev/urandom | head -c "$1"
}

: "${BROADCAST_CONNECTION:=reverb}"
export BROADCAST_CONNECTION

if [ -z "$REVERB_APP_KEY" ]; then
    _keys=/app/storage/state/reverb.json
    _old_keys=/app/storage/framework/reverb.json

    mkdir -p /app/storage/state

    # Kept in storage/framework before storage/state was a volume: moved, so a
    # tab open across the upgrade reconnects with the key it already holds.
    if [ ! -s "$_keys" ] && [ -s "$_old_keys" ]; then
        mv "$_old_keys" "$_keys"
    fi

    if [ ! -s "$_keys" ]; then
        printf '{"app_id":"%s","key":"%s","secret":"%s"}\n' \
            "$(od -An -N3 -tu4 /dev/urandom | tr -d ' ')" "$(_random 20)" "$(_random 32)" > "$_keys"
        chmod 640 "$_keys"
        echo "Generated Reverb keys in $_keys."
    fi
fi

# Started in a loop so a crash costs two seconds of live updates rather than
# all of them until the container restarts: without the socket, every banner
# and figure that shows work in progress looks frozen while the work goes on.
# A memory limit of its own: Reverb is a server that runs as long as the
# container, not a request, and the 128M a request gets ran out under a
# library-wide artwork fetch (thousands of signals, each printed by --debug in
# dev) — after which nothing on any page refreshed until a restart.
start_reverb() {
    (
        while true; do
            su-exec "$WEB_USER" php -d memory_limit=512M /app/artisan reverb:start --host=127.0.0.1 --port=8080 "$@"
            echo "Reverb exited; restarting in 2s." >&2
            sleep 2
        done
    ) &
}
