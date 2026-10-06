#!/bin/bash
set -e

# Set default values if not provided
USER=${AUTH_USER:-retrobite}
PASS=${AUTH_PASS:-retrobite}
HOST_IP=${HOST_IP:-}

echo "===================================="
echo "======  retroBITE Starting..  ======"
echo "===================================="

# The compose default, or .env.example's placeholder.
case "$PASS" in
    retrobite | change-me*)
        echo "WARNING: AUTH_PASS is the default. Anyone on the network can read and write the library over SMB and FTP; set AUTH_PASS." >&2
        ;;
esac

# One account serves both protocols. It needs a real shell: vsftpd's PAM stack
# ends with pam_shells.so, which refuses any user whose shell is not in
# /etc/shells — and /sbin/nologin never is.
#
# And it takes the ids of whoever owns the library, the way the web container's
# user-setup.sh does, rather than the library being changed to suit it. A
# network mount — sshfs, NFS — belongs to the machine that exports it and will
# not let anybody here reassign its files; matched ids read and write it as they
# are. A root-owned /games is a plain local folder, and keeps the old defaults.
GAMES_UID=$(stat -c '%u' /games 2>/dev/null || echo 0)
GAMES_GID=$(stat -c '%g' /games 2>/dev/null || echo 0)

if ! id "$USER" &>/dev/null; then
    if [ "$GAMES_UID" != "0" ]; then
        GAMES_GROUP=$(getent group "$GAMES_GID" | cut -d: -f1)

        if [ -z "$GAMES_GROUP" ]; then
            groupadd -g "$GAMES_GID" "$USER"
            GAMES_GROUP="$USER"
        fi

        useradd -m -d "/home/$USER" -s /bin/bash -u "$GAMES_UID" -o -g "$GAMES_GROUP" "$USER"
    else
        useradd -m -d "/home/$USER" -s /bin/bash "$USER"
    fi
elif [ "$GAMES_UID" != "0" ] && [ "$(id -u "$USER")" != "$GAMES_UID" ]; then
    # A container kept from before the library moved: follow the new owner.
    usermod -o -u "$GAMES_UID" "$USER"
fi

# Set the Samba password, then the Unix one FTP authenticates against.
echo -e "$PASS\n$PASS" | smbpasswd -a -s "$USER"
smbpasswd -e "$USER"
echo "$USER:$PASS" | chpasswd

# Ensure FTP user has access to games directory
usermod -aG users,nogroup "$USER" 2>/dev/null || true

# vsftpd needs this to exist for its privilege-separation chroot. /var/run is a
# tmpfs, so whatever the package created at build time is gone by now — without
# it every session dies on "500 OOPS: vsftpd: not found: directory given in
# 'secure_chroot_dir'".
mkdir -p /var/run/vsftpd/empty

# vsftpd advertises this address for passive-mode data connections, so it has to
# be one the client can actually reach. A stale value does not fail loudly — the
# control connection still works and only the transfer hangs.
#
# Derive it from the default route when HOST_IP is unset, so a laptop that moves
# between networks keeps working on restart. An explicit HOST_IP always wins.
if [ -z "$HOST_IP" ]; then
    HOST_IP=$(ip route get 1.1.1.1 2>/dev/null \
        | awk '{ for (i = 1; i < NF; i++) if ($i == "src") { print $(i + 1); exit } }')

    if [ -n "$HOST_IP" ]; then
        echo "→ detected host IP: $HOST_IP"
    else
        echo "⚠ could not detect host IP — set HOST_IP in .env, or FTP passive mode will fail"
    fi
fi

if [ -n "$HOST_IP" ]; then
    echo "Configuring FTP passive mode with IP: $HOST_IP"
    sed -i "s/^pasv_address=.*/pasv_address=$HOST_IP/" /etc/vsftpd.conf
fi

# The console subdirectories served over the network. Created here, not in the
# Dockerfile: /games is a bind mount, so anything made at build time is shadowed
# the moment the volume is attached.
#
# This is the only place the list lives. GAME_FOLDERS creates the directories;
# SMB_SHARES is the subset that also gets its own SMB share, because a loader
# like OPL connects to a share named after the console rather than browsing
# /games. FTP-only consoles need the directory but no share of their own.
GAME_FOLDERS=${GAME_FOLDERS:-ps2 ps3 gc wii xbox dreamcast}
SMB_SHARES=${SMB_SHARES:-ps2 gc wii}

for folder in $GAME_FOLDERS; do
    # One folder refused must not stop the shares from starting.
    mkdir -p "/games/$folder" || echo "WARNING: could not create /games/$folder" >&2
done

# Generated fresh each boot so a changed SMB_SHARES never leaves a stale share
# behind. smb.conf includes this file; an empty one is valid.
: > /etc/samba/shares.conf
for share in $SMB_SHARES; do
    cat >> /etc/samba/shares.conf <<SHARE
[$share]
   comment = retroBite $share Library
   path = /games/$share
   browseable = yes
   writable = yes
   guest ok = no
   valid users = @users
   read only = no
   create mask = 0775
   directory mask = 0775
   min protocol = NT1
   max protocol = SMB3

SHARE
done

# Only when the share account cannot already write to the library, and never
# fatally. With the ids matched above this is skipped outright — which is also
# what keeps a boot from walking every file of a remote library over the
# network. A mount that refuses ownership changes gets one warning, not a
# restart loop: its owner decides who may write to it, not this container.
if su -s /bin/sh "$USER" -c 'test -w /games'; then
    echo "Library is writable as $USER; its ownership is left alone."
elif chown -R :users /games 2>/dev/null && chmod -R g+rwX /games 2>/dev/null; then
    echo "Library handed to the users group."
else
    echo "WARNING: /games is not writable as $USER, and its ownership cannot be changed from here (a network mount?). Uploads over SMB and FTP will fail until its owner allows them." >&2
fi

# Three daemons, kept alive. supervisord did this before, but it is a Python
# program and pulled a CPython runtime in purely to run three execs. Docker's own
# init (`init: true` in compose) reaps zombies and forwards signals, so all this
# has to do is start them and put back whichever one dies.
declare -A COMMANDS=(
    [smbd]="/usr/sbin/smbd --foreground --no-process-group"
    [nmbd]="/usr/sbin/nmbd --foreground --no-process-group"
    [vsftpd]="/usr/sbin/vsftpd /etc/vsftpd.conf"
)
declare -A PIDS=()

# Launch one daemon and remember its pid.
start_service() {
    local name="$1"

    ${COMMANDS[$name]} &
    PIDS[$name]=$!

    echo "→ started $name (pid ${PIDS[$name]})"
}

# Stop everything on the way out, so `docker stop` is prompt rather than a
# ten-second wait for SIGKILL.
stop_services() {
    trap - TERM INT

    echo "→ stopping"
    for name in "${!PIDS[@]}"; do
        kill "${PIDS[$name]}" 2>/dev/null || true
    done
    wait

    exit 0
}

trap stop_services TERM INT

for name in "${!COMMANDS[@]}"; do
    start_service "$name"
done

# `wait -n` returns as soon as any one of them exits; whichever it was gets
# restarted, matching supervisord's autorestart.
while true; do
    wait -n || true

    for name in "${!PIDS[@]}"; do
        if ! kill -0 "${PIDS[$name]}" 2>/dev/null; then
            echo "⚠ $name exited, restarting"
            start_service "$name"
        fi
    done
done