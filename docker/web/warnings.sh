#!/bin/sh
# Settings worth a second look, said once at start into the container's log —
# which is where Portainer, Dockhand and a NAS's container manager show it.
#
# Sourced, not executed. Never fatal: every one of these still runs, it is just
# not what anybody wants on a network with consoles on it. See
# docs/configuration.md for each setting.

_warn() {
    echo "WARNING: $*" >&2
}

# The compose default, or .env.example's placeholder.
case "${DB_PASSWORD:-retrobite}" in
    retrobite | change-me*) _warn "DB_PASSWORD is the default. Set it (and DB_ROOT_PASSWORD) before the database's first start; it is fixed when the database is created." ;;
esac

if [ "${APP_ENV:-production}" = production ] && [ "${APP_DEBUG:-false}" = true ]; then
    _warn "APP_DEBUG is on: an error page shows the application's configuration to whoever caused it. Set APP_DEBUG=false."
fi

if [ -z "${HOST_IP:-}" ] && [ -z "${SHARE_HOST:-}" ]; then
    _warn "HOST_IP is not set, so the dashboard cannot tell whether SMB and FTP are up, and Settings → Destinations searches no network. Set it to this machine's LAN address."
fi
