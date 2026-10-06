#!/bin/sh
# The application key, before anything runs PHP.
#
# Sourced, not executed, by both entrypoints. Taken from the environment when
# it sets APP_KEY; otherwise generated once and kept in storage/state/app.key,
# which config/app.php reads when the environment has none — so `docker exec …
# php artisan`, which starts outside this process tree, uses the same key.
#
# The key encrypts the passwords retroBITE stores (Settings → ScreenScraper,
# RetroAchievements, Destinations), so it must outlive the container:
# storage/state is a volume in docker-compose.yml. A key lost is every stored
# password unreadable, not a failed start.
#
# Nothing to do by hand, which is the point: a stack started from Portainer,
# Dockhand or a NAS's container manager has no shell to run key:generate in.

STATE_DIR=/app/storage/state

mkdir -p "$STATE_DIR"

if [ -z "$APP_KEY" ]; then
    _key_file="$STATE_DIR/app.key"

    if [ ! -s "$_key_file" ]; then
        printf 'base64:%s\n' "$(head -c 32 /dev/urandom | base64 | tr -d '\n')" > "$_key_file"
        chmod 640 "$_key_file"
        echo "Generated an application key in $_key_file. Back it up with the database: stored passwords cannot be read without it."
    fi

    APP_KEY=$(tr -d '\n' < "$_key_file")
    export APP_KEY
fi
