#!/bin/bash
#
# Checks that the SMB and FTP shares are actually serving the game library.
#
# Run from the host against the published ports, which is the path a console
# takes. Beyond "does it connect", it compares what each share lists against
# what is really in ./games — a share can mount, authenticate and list happily
# while serving an empty directory, which is exactly how the last breakage hid.
#
# Usage: ./tests/test-shares.sh [--host IP] [--user U] [--pass P]
#                               [--smb-port N] [--ftp-port N] [--games DIR]
#
# With no arguments it reads HOST_IP, AUTH_USER and AUTH_PASS from .env, so this
# checks whatever the running stack is configured for:
#
#   ./tests/test-shares.sh
#
# Against another machine, or a stack whose credentials differ from this .env:
#
#   ./tests/test-shares.sh --host 192.168.50.250 --user retrobite --pass hunter2
#
# SMB on a port other than 445 — a host running its own Samba occupies 445, and
# the dev stack published 446 before that was corrected:
#
#   ./tests/test-shares.sh --smb-port 446
#
# FTP on a control port other than 21. The passive range is not a flag: it is
# whatever vsftpd.conf declares, and a successful listing proves it opened:
#
#   ./tests/test-shares.sh --ftp-port 2121
#
# Against a library somewhere other than ./games — the directory listings are
# compared against it, so it must be the one the container mounts:
#
#   ./tests/test-shares.sh --games /mnt/roms
#
# Exit status is 0 only when every check passes, so it can gate a deploy:
#
#   ./tests/test-shares.sh && ./deploy.sh

set -uo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${REPO_DIR}/.env"

HOST=""
USERNAME=""
PASSWORD=""
SMB_PORT=445
FTP_PORT=21
GAMES_DIR="${REPO_DIR}/games"

PASSED=0
FAILED=0

# Read one key from .env without sourcing it, so a stray command in there
# cannot run and quotes around a value do not become part of it.
read_env() {
    local key="$1"

    [ -f "$ENV_FILE" ] || return 0

    sed -n "s/^[[:space:]]*${key}=//p" "$ENV_FILE" | tail -1 | sed 's/^"\(.*\)"$/\1/; s/^'"'"'\(.*\)'"'"'$/\1/'
}

# The header comment is the documentation; print it rather than keeping a second
# copy here that can drift from it.
usage() {
    awk 'NR > 2 && /^#/ { sub(/^# ?/, ""); print; next } NR > 2 { exit }' "${BASH_SOURCE[0]}"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --host)      HOST="$2";      shift 2 ;;
        --user)      USERNAME="$2";  shift 2 ;;
        --pass)      PASSWORD="$2";  shift 2 ;;
        --smb-port)  SMB_PORT="$2";  shift 2 ;;
        --ftp-port)  FTP_PORT="$2";  shift 2 ;;
        --games)     GAMES_DIR="$2"; shift 2 ;;
        -h|--help)   usage; exit 0 ;;
        *)           echo "Unknown option: $1"; echo; usage; exit 1 ;;
    esac
done

HOST="${HOST:-$(read_env HOST_IP)}"
USERNAME="${USERNAME:-$(read_env AUTH_USER)}"
PASSWORD="${PASSWORD:-$(read_env AUTH_PASS)}"

HOST="${HOST:-127.0.0.1}"
USERNAME="${USERNAME:-retrobite}"
PASSWORD="${PASSWORD:-retrobite}"

ok()   { echo "  ✓ $1"; PASSED=$((PASSED + 1)); }

# "1 file" rather than "1 files".
plural() { [ "$1" -eq 1 ] && echo "$1 $2" || echo "$1 $3"; }
fail() { echo "  ✗ $1"; FAILED=$((FAILED + 1)); }
note() { echo "  → $1"; }
warn() { echo "  ⚠ $1"; }

# Console directories present on disk, one per line. This is the yardstick both
# protocols are measured against.
expected_dirs() {
    [ -d "$GAMES_DIR" ] || return 0

    find "$GAMES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null | sort
}

# Which of the expected directories are missing from a listing.
missing_from() {
    local listing="$1" missing=""

    while IFS= read -r dir; do
        [ -n "$dir" ] || continue
        grep -qx -- "$dir" <<<"$listing" || missing="${missing}${dir} "
    done <<<"$(expected_dirs)"

    echo "$missing"
}

port_open() {
    nc -z -w 2 "$HOST" "$1" 2>/dev/null
}

require() {
    command -v "$1" >/dev/null 2>&1 || { echo "✗ $1 is required but not installed."; exit 1; }
}

require smbclient
require curl
require nc

echo "retroBITE share check"
echo "  host $HOST · user $USERNAME · library $GAMES_DIR"
echo

EXPECTED_COUNT=$(expected_dirs | grep -c . || true)

if [ "$EXPECTED_COUNT" -eq 0 ]; then
    warn "No console directories in $GAMES_DIR — install one before trusting this run."
fi

# ── SMB ──────────────────────────────────────────────────────────────────────
echo "SMB  //$HOST/games"

for port in 139 "$SMB_PORT"; do
    if port_open "$port"; then
        ok "port $port open"
    else
        fail "port $port closed"
    fi
done

SMB_AUTH=$(smbclient -L "//$HOST" -U "$USERNAME%$PASSWORD" -p "$SMB_PORT" 2>&1)

if [ $? -eq 0 ] && ! grep -qi "NT_STATUS_LOGON_FAILURE" <<<"$SMB_AUTH"; then
    ok "authenticated as $USERNAME"
    note "shares: $(grep -E '^\s+\w+\s+Disk' <<<"$SMB_AUTH" | awk '{print $1}' | tr '\n' ' ')"
else
    fail "authentication failed"
    note "$(head -3 <<<"$SMB_AUTH")"
fi

SMB_ROOT=$(smbclient "//$HOST/games" -U "$USERNAME%$PASSWORD" -p "$SMB_PORT" -c 'ls' 2>&1)

if [ $? -eq 0 ]; then
    SMB_DIRS=$(grep -E '^\s+\S+\s+D' <<<"$SMB_ROOT" | awk '{print $1}' | grep -vE '^\.\.?$' | sort)
    ok "listed the games share ($(plural "$(grep -c . <<<"$SMB_DIRS")" directory directories))"

    SMB_MISSING=$(missing_from "$SMB_DIRS")

    if [ -z "${SMB_MISSING// /}" ]; then
        [ "$EXPECTED_COUNT" -gt 0 ] && ok "serves the library on disk ($EXPECTED_COUNT consoles)"
    else
        fail "share is missing: ${SMB_MISSING}"
        note "the share is mounted somewhere other than the library"
    fi

    while IFS= read -r dir; do
        [ -n "$dir" ] || continue
        listing=$(smbclient "//$HOST/games" -U "$USERNAME%$PASSWORD" -p "$SMB_PORT" -c "cd \"$dir\"; ls" 2>&1)

        if [ $? -eq 0 ]; then
            files=$(grep -v 'blocks of size' <<<"$listing" | awk 'NF>=8 && $(NF-6) !~ /D/' | wc -l)
            subdirs=$(awk 'NF>=8 && $(NF-6) ~ /D/ {print $1}' <<<"$listing" | grep -vcE '^\.\.?$')
            ok "$dir/ ($(plural "$files" file files), $(plural "$subdirs" subdirectory subdirectories))"
        else
            fail "$dir/ could not be listed"
        fi
    done <<<"$SMB_DIRS"
else
    fail "could not list the games share"
    note "$(head -3 <<<"$SMB_ROOT")"
fi

echo

# ── FTP ──────────────────────────────────────────────────────────────────────
echo "FTP  ftp://$HOST/"

if port_open "$FTP_PORT"; then
    ok "port $FTP_PORT open"
else
    fail "port $FTP_PORT closed"
fi

FTP_ROOT=$(curl -s --ftp-pasv --connect-timeout 5 --max-time 20 \
    -u "$USERNAME:$PASSWORD" "ftp://$HOST:$FTP_PORT/" 2>&1)

if [ $? -eq 0 ] && [ -n "$FTP_ROOT" ]; then
    ok "authenticated as $USERNAME"

    FTP_DIRS=$(grep '^d' <<<"$FTP_ROOT" | awk '{print $NF}' | grep -vE '^\.\.?$' | sort)
    ok "listed the root ($(plural "$(grep -c . <<<"$FTP_DIRS")" directory directories))"

    FTP_MISSING=$(missing_from "$FTP_DIRS")

    if [ -z "${FTP_MISSING// /}" ]; then
        [ "$EXPECTED_COUNT" -gt 0 ] && ok "lands in the library, not a home directory"
    else
        fail "root is missing: ${FTP_MISSING}"
        note "local_root is not pointing at the library"
    fi

    while IFS= read -r dir; do
        [ -n "$dir" ] || continue
        listing=$(curl -s --ftp-pasv --connect-timeout 5 --max-time 20 \
            -u "$USERNAME:$PASSWORD" "ftp://$HOST:$FTP_PORT/$dir/" 2>&1)

        if [ $? -eq 0 ]; then
            ok "$dir/ ($(plural "$(grep -c '^-' <<<"$listing")" file files))"
        else
            fail "$dir/ could not be listed"
        fi
    done <<<"$FTP_DIRS"
else
    fail "authentication or listing failed"
    note "check the account's shell — vsftpd's PAM stack refuses /sbin/nologin"
fi

echo
echo "$PASSED passed, $FAILED failed"

[ "$FAILED" -eq 0 ]
