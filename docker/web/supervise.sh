#!/bin/sh
# Hand a long-running command to supervisord.
#
# Sourced, not executed. Defines one function for the entrypoints:
#
#   supervise scheduler 1 php /app/artisan schedule:work
#
# writes /etc/supervisor.d/<name>.ini, a program supervisord runs as $WEB_USER
# in /app, <count> copies of it, started again whenever it exits. Nothing is
# started here: supervisord reads these when the entrypoint execs it last.
# A count of 0 writes nothing.
#
# The directory is emptied on the first call of each boot. A container that is
# restarted keeps its filesystem, and a program written by the last boot —
# workers since set to 0 — must not come back.

SUPERVISE_DIR=/etc/supervisor.d

supervise() {
    _program="$1"
    _count="$2"
    shift 2

    if [ -z "$_supervise_ready" ]; then
        mkdir -p "$SUPERVISE_DIR"
        rm -f "$SUPERVISE_DIR"/*.ini
        _supervise_ready=1
    fi

    [ "$_count" -gt 0 ] || return 0

    # supervisord splits the command the way a shell would and expands
    # %(name)s in it, so a literal % is doubled and a word with a space quoted.
    _command=""
    for _arg in "$@"; do
        _arg=$(printf '%s' "$_arg" | sed 's/%/%%/g')
        case "$_arg" in
            *" "*) _arg="\"$_arg\"" ;;
        esac
        _command="$_command $_arg"
    done

    cat > "$SUPERVISE_DIR/$_program.ini" <<INI
[program:$_program]
command=${_command# }
user=$WEB_USER
directory=/app
numprocs=$_count
process_name=%(program_name)s_%(process_num)02d
autorestart=true
startsecs=3
startretries=10
stopwaitsecs=10
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
redirect_stderr=true
INI
}
