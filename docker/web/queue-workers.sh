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
#
# Each one runs in a loop that starts it again whenever it exits. Nothing else
# would: nginx is PID 1, so the container outlives any worker, and workers do
# exit — queue:work on --max-time and whenever a job overruns its timeout,
# queue:listen when a child it is waiting on overruns --timeout. Without the
# loop, each of those left its queue undrained until somebody restarted the
# container by hand. The pause keeps a worker that dies on boot from spinning.

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
        (
            # The entrypoints run under set -e, which a subshell inherits: the
            # first non-zero exit would end the loop instead of the worker.
            set +e

            while :; do
                su-exec "$WEB_USER" "$@"
                echo "$_name worker exited with status $?; starting it again." >&2
                sleep 3
            done
        ) &
        _i=$((_i + 1))
    done

    echo "$_name: $_count worker(s)."
}
