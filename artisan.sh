#!/bin/bash
# Run artisan inside the web container.
#
# Picks the dev stack when docker-compose.dev.yml is in play, otherwise prod.
# Both stacks name the service retrobite-web, so `exec` targets it either way.

if [ -e "docker-compose.dev.yml" ] && docker compose -f docker-compose.yml -f docker-compose.dev.yml ps --status running --services 2>/dev/null | grep -q '^retrobite-web$'; then
    docker compose -f docker-compose.yml -f docker-compose.dev.yml exec retrobite-web php artisan "$@"
else
    docker compose -f docker-compose.yml exec retrobite-web php artisan "$@"
fi
