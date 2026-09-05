FROM ubuntu:22.04

# Prevent interactive prompts during package installation
ENV DEBIAN_FRONTEND=noninteractive

# Install Samba, FTP server, and utilities
RUN apt-get update && apt-get install -y \
    samba \
    samba-common-bin \
    vsftpd \
    supervisor \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Create shared directory for games with console-specific folders
RUN mkdir -p /games/ps2 /games/ps3 /games/gc /games/wii /games/xbox /games/dreamcast && \
    chmod -R 777 /games

# FTP user will be created dynamically in entrypoint to match SMB user

# Copy files
COPY docker/smb.conf /etc/samba/smb.conf
COPY docker/vsftpd.conf /etc/vsftpd.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh

# Expose ports
# SMB ports
EXPOSE 139 445
# FTP ports
EXPOSE 20 21 21100-21110

ENTRYPOINT ["/entrypoint.sh"]
