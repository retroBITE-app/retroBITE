FROM debian:bookworm-slim

# Prevent interactive prompts during package installation
ENV DEBIAN_FRONTEND=noninteractive

# Docs, man pages and locales are dead weight in a file server. Excluding them at
# unpack time is cheaper and more complete than deleting them afterwards.
RUN printf '%s\n' \
        'path-exclude /usr/share/doc/*' \
        'path-include /usr/share/doc/*/copyright' \
        'path-exclude /usr/share/man/*' \
        'path-exclude /usr/share/locale/*' \
        > /etc/dpkg/dpkg.cfg.d/01-nodoc

# --no-install-recommends is what keeps samba-vfs-modules out, and with it the Ceph,
# Gluster and Boost libraries a plain SMB share never loads. Safe because smb.conf
# declares no `vfs objects`, so only the builtins inside samba-libs are ever used.
# iproute2 is for the entrypoint's host-IP detection: the container needs the
# address of the default-route interface, and `hostname -I` cannot tell you
# which of its seven answers that is.
RUN apt-get update && apt-get install -y --no-install-recommends \
    samba \
    samba-common-bin \
    vsftpd \
    iproute2 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /var/cache/debconf/*

# Create shared directory for games with console-specific folders
RUN mkdir -p /games/ps2 /games/ps3 /games/gc /games/wii /games/xbox /games/dreamcast && \
    chmod -R 777 /games

# FTP user will be created dynamically in entrypoint to match SMB user

# Copy files
COPY docker/smb.conf /etc/samba/smb.conf
COPY docker/vsftpd.conf /etc/vsftpd.conf
COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh

# Expose ports
# SMB ports
EXPOSE 139 445
# FTP ports
EXPOSE 20 21 21100-21110

ENTRYPOINT ["/entrypoint.sh"]
