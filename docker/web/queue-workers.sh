#!/bin/sh
# How many queue workers to start, from the environment.
#
# Sourced, not executed. Defines one function, workers(), for the entrypoint:
#
#   workers QUEUE_WORKERS_MEDIA 1 php /app/artisan queue:work --queue=media ...
#
# starts that command in the background as $WEB_USER, as many times as the
# named variable says, or the default when it is unset or empty. Zero is a
# real answer and starts none — a queue nobody wants worked, or one worked by
# another machine. Anything that is not a whole number is refused and the
# default used instead, loudly, rather than starting no workers in silence.

workers() {
    _name="$1"
    _default="$2"
    shift 2

    eval "_count=\${$_name:-$_default}"

    case "$_count" in
        '' | *[!0-9]*)
            echo "$_name=$_count is not a whole number; starting $_default." >&2
            _count="$_default"
            ;;
    esac

    _i=0
    while [ "$_i" -lt "$_count" ]; do
        su-exec "$WEB_USER" "$@" &
        _i=$((_i + 1))
    done

    echo "$_name: $_count worker(s)."
}
