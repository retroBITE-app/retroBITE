#!/bin/bash
set -e

# Set default values if not provided
USER=${AUTH_USER:-retrobite}
PASS=${AUTH_PASS:-retrobite}
HOST_IP=${HOST_IP:-}

echo "===================================="
echo "======  retroBITE Starting..  ======"
echo "===================================="

# One account serves both protocols. It needs a real shell: vsftpd's PAM stack
# ends with pam_shells.so, which refuses any user whose shell is not in
# /etc/shells — and /sbin/nologin never is.
if ! id "$USER" &>/dev/null; then
    useradd -m -d "/home/$USER" -s /bin/bash "$USER"
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

# Configure FTP passive mode IP if provided
if [ -n "$HOST_IP" ]; then
    echo "Configuring FTP passive mode with IP: $HOST_IP"
    sed -i "s/^pasv_address=.*/pasv_address=$HOST_IP/" /etc/vsftpd.conf
fi

# Ensure games directory has correct permissions
chown -R :users /games
chmod -R 775 /games

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