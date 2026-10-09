#!/bin/sh
# How many queue workers to start, from the environment.
#
# Sourced, not executed, after supervise.sh. Defines one function, workers(),
# for the entrypoint:
#
#   workers QUEUE_WORKERS_MEDIA 1 php /app/artisan queue:work --queue=media ...
#
# has supervisord run that command as $WEB_USER, as many times as the named
# variable says, or the default when it is unset or empty. Zero is a real
# answer and starts none — a queue nobody wants worked, or one worked by
# another machine. Anything that is not a whole number is refused and the
# default used instead, loudly, rather than starting no workers in silence.
#
# supervisord starts each one again whenever it exits, and workers do exit —
# queue:work on --max-time and whenever a job overruns its timeout,
# queue:listen when a child it is waiting on overruns --timeout. The program
# is named after the variable: QUEUE_WORKERS_MEDIA is queue-media_00, _01, …
# in `supervisorctl status`.

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

    _program=$(printf '%s' "${_name#QUEUE_WORKERS_}" | tr 'A-Z_' 'a-z-')
    supervise "queue-$_program" "$_count" "$@"

    echo "$_name: $_count worker(s)."
}
