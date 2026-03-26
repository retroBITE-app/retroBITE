#!/bin/bash
set -e

# Set default values if not provided
USER=${USER:-retrobite}
PASS=${PASS:-retrobite}
HOST_IP=${HOST_IP:-}

echo "===================================="
echo "======  retroBITE Starting..  ======"
echo "===================================="

# Create Samba user if it doesn't exist
if ! id "$USER" &>/dev/null; then
    useradd -M -s /sbin/nologin "$USER"
fi

# Set Samba password
echo -e "$PASS\n$PASS" | smbpasswd -a -s "$USER"
smbpasswd -e "$USER"

# Create/update FTP user to match SMB user
if ! id "$USER" &>/dev/null 2>&1; then
    useradd -m -d /home/$USER -s /bin/bash "$USER"
fi
echo "$USER:$PASS" | chpasswd

# Ensure FTP user has access to games directory
usermod -aG users,nogroup "$USER" 2>/dev/null || true

# Configure FTP passive mode IP if provided
if [ -n "$HOST_IP" ]; then
    echo "Configuring FTP passive mode with IP: $HOST_IP"
    sed -i "s/^pasv_address=.*/pasv_address=$HOST_IP/" /etc/vsftpd.conf
fi

# Ensure games directory has correct permissions
chown -R :users /games
chmod -R 775 /games

# Get the container's IP address
CONTAINER_IP=$(hostname -I | awk '{print $1}')

# Start supervisor to manage all services
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf