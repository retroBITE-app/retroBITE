#!/bin/sh
# Align the php-fpm user with whoever owns the bind-mounted files on the host,
# so files written from inside the container are usable outside it and vice
# versa. Sets WEB_USER and WEB_GROUP for the caller to chown with.
#
# Sourced, not executed — it exports nothing, it just sets two variables.

WEB_USER=www-data
WEB_GROUP=www-data

_ref_dir="$1"
_host_uid=$(stat -c '%u' "$_ref_dir" 2>/dev/null || echo 0)
_host_gid=$(stat -c '%g' "$_ref_dir" 2>/dev/null || echo 0)

if [ "$_host_uid" != "0" ]; then
    deluser www-data 2>/dev/null || true

    # A host GID often collides with one Alpine already ships — 100 is `users`,
    # which is exactly what a Linux host hands out. Creating a second group with
    # that GID fails, so reuse the existing one instead.
    _existing=$(getent group "$_host_gid" | cut -d: -f1)
    if [ -n "$_existing" ]; then
        WEB_GROUP="$_existing"
    else
        addgroup -g "$_host_gid" -S www-data
        WEB_GROUP=www-data
    fi

    adduser -u "$_host_uid" -G "$WEB_GROUP" -S -D -H www-data
fi

# Supplementary membership for the SMB/FTP side, which writes as :users.
addgroup www-data users 2>/dev/null || true

# The stock pool config hardcodes `group = www-data`. When WEB_GROUP turned out
# to be something else, php-fpm refuses to start without this override.
cat > /usr/local/etc/php-fpm.d/zz-user.conf <<EOF
[www]
user = $WEB_USER
group = $WEB_GROUP
EOF
