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

# Start supervisor to manage all services
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf