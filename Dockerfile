# ps3netsrv, what webMAN MOD on a PS3 streams ISOs and game folders from.
# Built from a pinned tag rather than taken from a release, because the
# releases carry no arm64 Linux binary. Makefile.linux links it statically
# against the PolarSSL it bundles — upstream's own recommendation over the
# meson build — so the runtime stage needs no libraries for it.
FROM debian:bookworm-slim AS ps3netsrv

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    g++ \
    git \
    make \
    && rm -rf /var/lib/apt/lists/*

ARG PS3NETSRV_TAG=20260913
RUN git clone --depth 1 --branch "$PS3NETSRV_TAG" https://github.com/aldostools/ps3netsrv.git /src/ps3netsrv \
    && make -C /src/ps3netsrv -f Makefile.linux BUILD_DATE="$PS3NETSRV_TAG" \
    && strip /src/ps3netsrv/ps3netsrv

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

# FTP user will be created dynamically in entrypoint to match SMB user

# Copy files
COPY docker/smb.conf /etc/samba/smb.conf
COPY docker/vsftpd.conf /etc/vsftpd.conf
COPY docker/entrypoint.sh /entrypoint.sh
COPY --from=ps3netsrv /src/ps3netsrv/ps3netsrv /usr/local/bin/ps3netsrv

# A dynamic ps3netsrv would start here and fail at runtime on a missing
# library, so refuse the build instead.
#
# ps3netsrv's root is a directory of the image's own. webMAN MOD looks for
# fixed folder names under it — PS3ISO, PS2ISO, PSXISO — and the entrypoint
# links each to a console folder of the library (PS3NETSRV_FOLDERS), so
# nothing is created inside /games for webMAN's sake.
RUN chmod +x /entrypoint.sh \
    && ldd /usr/local/bin/ps3netsrv 2>&1 | grep -q 'not a dynamic executable' \
    && mkdir -p /srv/ps3netsrv

# Expose ports
# SMB ports
EXPOSE 139 445
# FTP ports
EXPOSE 20 21 21100-21110
# ps3netsrv
EXPOSE 38008

# smbd answering on 445. FTP is left out: vsftpd is restarted by the
# entrypoint when it dies, and SMB is what consoles mostly use.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD bash -c '</dev/tcp/127.0.0.1/445' || exit 1

ENTRYPOINT ["/entrypoint.sh"]
