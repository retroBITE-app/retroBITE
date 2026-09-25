#!/bin/bash
# Run npm inside the dev web container: ./npm.sh run build, ./npm.sh install x.
#
# The container is the one place node runs. It keeps its own node_modules in a
# volume, because the native build tools (Tailwind's oxide, lightningcss,
# Rolldown) differ between macOS and the container's Alpine Linux, and one tree
# cannot serve both. Running npm on the host as well means two trees to keep in
# step, which is how a package added in one goes missing from the other.
#
# Dev stack only: the production image builds its assets in its own stage and
# carries no node.

if ! docker compose -f docker-compose.dev.yml ps --status running --services 2>/dev/null | grep -q '^retrobite-web$'; then
    echo "The dev stack is not running. Start it with: docker compose -f docker-compose.dev.yml up -d" >&2
    exit 1
fi

# As the web user, so what npm writes — node_modules, public/build — is owned
# the way the rest of the app expects.
exec docker compose -f docker-compose.dev.yml exec -u www-data retrobite-web npm "$@"
