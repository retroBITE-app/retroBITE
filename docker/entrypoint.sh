#!/bin/bash
set -e

# Set default values if not provided
USER=${AUTH_USER:-retrobite}
PASS=${AUTH_PASS:-retrobite}
HOST_IP=${HOST_IP:-}
PS3NETSRV=${PS3NETSRV:-true}
PS3NETSRV_WHITELIST=${PS3NETSRV_WHITELIST:-}
PS3NETSRV_FOLDERS=${PS3NETSRV_FOLDERS:-PS3ISO=ps3 PS2ISO=ps2 PSXISO=psx}

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

case "$PS3NETSRV" in
    true | 1 | yes | on) PS3NETSRV=true ;;
    *) PS3NETSRV=false ;;
esac

# webMAN's folder names under ps3netsrv's root, each linked to a console folder
# of the library: PS3NETSRV_FOLDERS="PS3ISO=ps3 PS2ISO=ps2 PSXISO=psx". Made
# fresh each boot, so a changed list never leaves a stale link. Only names
# webMAN reads, and only one plain folder name each: a link must not lead out
# of /games. A folder the library does not have yet simply lists empty.
PS3NETSRV_SERVES=""

if [ "$PS3NETSRV" = true ]; then
    find /srv/ps3netsrv -mindepth 1 -maxdepth 1 -type l -delete

    for pair in $PS3NETSRV_FOLDERS; do
        name=${pair%%=*}
        folder=${pair#*=}

        case "$name" in
            PS3ISO | PS2ISO | PSXISO | PSPISO | BDISO | DVDISO | GAMES | PKG) ;;
            *)
                echo "WARNING: PS3NETSRV_FOLDERS: $name is not a folder webMAN MOD reads; skipped." >&2
                continue
                ;;
        esac

        # No path, and nothing hidden: .retrobite-uploads is upload staging.
        if [ "$pair" = "$name" ] || [ -z "$folder" ] || [[ "$folder" == .* ]] || [[ "$folder" == */* ]]; then
            echo "WARNING: PS3NETSRV_FOLDERS: $pair does not name one library folder; skipped." >&2
            continue
        fi

        ln -sfn "/games/$folder" "/srv/ps3netsrv/$name"
        PS3NETSRV_SERVES="$PS3NETSRV_SERVES /games/$folder"
        echo "→ ps3netsrv: $name is /games/$folder"
    done

    # ps3netsrv takes one address with * for any part (192.168.1.*) and exits
    # on anything else — a CIDR such as 192.168.1.0/24 included — which
    # supervisord would then start again until it gave up. Refused here
    # instead, and not started at all: a whitelist that was meant to narrow
    # who connects must not become no whitelist. SMB and FTP carry on.
    if [ -n "$PS3NETSRV_WHITELIST" ] && ! [[ "$PS3NETSRV_WHITELIST" =~ ^([0-9]{1,3}|\*)(\.([0-9]{1,3}|\*)){3}$ ]]; then
        echo "ERROR: PS3NETSRV_WHITELIST=$PS3NETSRV_WHITELIST is not an address ps3netsrv accepts (one address, * for any part, e.g. 192.168.1.*); ps3netsrv is not started." >&2
        PS3NETSRV=refused
    elif [ -z "$PS3NETSRV_WHITELIST" ]; then
        echo "WARNING: ps3netsrv has no authentication, and anyone on the network can read${PS3NETSRV_SERVES:- nothing} through it (read-only: nothing can be changed). Set PS3NETSRV_WHITELIST (e.g. 192.168.1.*) to limit who connects." >&2
    fi
fi

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

# ps3netsrv, when it is on, as the share account rather than root: the
# protocol has no login, and though this build is read-only (see the
# Dockerfile), it gets no more than SMB and FTP do. Its root is the image's
# /srv/ps3netsrv, holding the links made above. Written fresh each boot, so
# switching it off leaves nothing behind for supervisord to start.
#
# Its stdin is /dev/null. On an error — the port already taken — ps3netsrv
# prints "Press ENTER to continue" and reads stdin, and the pipe supervisord
# gives every program never ends: it would sit there shown as RUNNING, serving
# nothing, for good. With nothing to read it exits and is started again.
mkdir -p /etc/supervisor/conf.d
rm -f /etc/supervisor/conf.d/ps3netsrv.conf

if [ "$PS3NETSRV" = true ]; then
    # Single-quoted, so the shell leaves 192.168.1.* alone; left out when
    # unset, because an empty argument is a whitelist ps3netsrv refuses. The
    # check above has made sure it holds no quote.
    PS3NETSRV_ARGS="/srv/ps3netsrv 38008"
    if [ -n "$PS3NETSRV_WHITELIST" ]; then
        PS3NETSRV_ARGS="$PS3NETSRV_ARGS '$PS3NETSRV_WHITELIST'"
    fi

    cat > /etc/supervisor/conf.d/ps3netsrv.conf <<PROGRAM
[program:ps3netsrv]
command=/bin/sh -c "exec /usr/local/bin/ps3netsrv $PS3NETSRV_ARGS < /dev/null"
user=$USER
autorestart=true
startsecs=3
startretries=10
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
redirect_stderr=true
PROGRAM
fi

# supervisord from here: smbd, nmbd, vsftpd and ps3netsrv, each started again
# when it exits (docker/supervisord.conf). Compose's `init: true` keeps tini
# as PID 1 above it, forwarding docker stop's signal and reaping orphans.
exec supervisord -c /etc/supervisor/supervisord.conf
