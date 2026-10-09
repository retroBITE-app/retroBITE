# ps3netsrv, what webMAN MOD on a PS3 streams ISOs and game folders from.
# Built from a pinned tag rather than taken from a release, because the
# releases carry no arm64 Linux binary. Makefile.linux links it statically
# against the PolarSSL it bundles — upstream's own recommendation over the
# meson build — so the runtime stage needs no libraries for it.
#
# Read-only: upstream's own READ_ONLY switch (what it ships as ps3netsrv_ro)
# leaves out create, write, delete, mkdir and rmdir, so a server with no login
# can serve the library but never change it. And patched: its guard against
# ".." checks only the first "/.." in a path, so "/PS3ISO/..x/../.." got past
# it; the patch checks every one.
FROM debian:bookworm-slim AS ps3netsrv

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    g++ \
    git \
    make \
    patch \
    && rm -rf /var/lib/apt/lists/*

# The tag names the release; the commit is what is built. A tag can be moved
# upstream, and a moved one now fails the build instead of changing it.
ARG PS3NETSRV_TAG=20260913
ARG PS3NETSRV_COMMIT=158a829a30dd9c5bc61e851f1d3505e2db91b08a
COPY patches/ps3netsrv-dotdot.patch /src/ps3netsrv-dotdot.patch
RUN git clone --depth 1 --branch "$PS3NETSRV_TAG" https://github.com/aldostools/ps3netsrv.git /src/ps3netsrv \
    && test "$(git -C /src/ps3netsrv rev-parse HEAD)" = "$PS3NETSRV_COMMIT" \
    && patch -d /src/ps3netsrv -p1 < /src/ps3netsrv-dotdot.patch \
    && sed -i -e 's/^#CFLAGS += -DREAD_ONLY/CFLAGS += -DREAD_ONLY/' -e 's/^#CPPFLAGS += -DREAD_ONLY/CPPFLAGS += -DREAD_ONLY/' /src/ps3netsrv/Makefile.linux \
    && grep -q '^CFLAGS += -DREAD_ONLY' /src/ps3netsrv/Makefile.linux \
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
# supervisor keeps the daemons running and starts any that dies again
# (docker/supervisord.conf). It brings Python with it; a restart loop written
# into the entrypoint did the job without, and kept getting it wrong.
RUN apt-get update && apt-get install -y --no-install-recommends \
    samba \
    samba-common-bin \
    vsftpd \
    iproute2 \
    supervisor \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /var/cache/debconf/*

# FTP user will be created dynamically in entrypoint to match SMB user

# Copy files
COPY docker/smb.conf /etc/samba/smb.conf
COPY docker/vsftpd.conf /etc/vsftpd.conf
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh
COPY --from=ps3netsrv /src/ps3netsrv/ps3netsrv /usr/local/bin/ps3netsrv

# A dynamic ps3netsrv would start here and fail at runtime on a missing
# library, and a writable one would let anyone on the network change the
# library, so refuse the build instead.
#
# ps3netsrv's root is a directory of the image's own. webMAN MOD looks for
# fixed folder names under it — PS3ISO, PS2ISO, PSXISO — and the entrypoint
# links each to a console folder of the library (PS3NETSRV_FOLDERS), so
# nothing is created inside /games for webMAN's sake.
RUN chmod +x /entrypoint.sh \
    && ldd /usr/local/bin/ps3netsrv 2>&1 | grep -q 'not a dynamic executable' \
    && grep -q 'READ-ONLY' /usr/local/bin/ps3netsrv \
    && mkdir -p /srv/ps3netsrv

# Expose ports
# SMB ports
EXPOSE 139 445
# FTP ports
EXPOSE 20 21 21100-21110
# ps3netsrv
EXPOSE 38008

# smbd answering on 445. FTP is left out: supervisord restarts vsftpd when it
# dies, and SMB is what consoles mostly use.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD bash -c '</dev/tcp/127.0.0.1/445' || exit 1

ENTRYPOINT ["/entrypoint.sh"]
