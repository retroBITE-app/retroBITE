#!/bin/bash
#
# PS2 disc inspection shared by ps2-game-ids.sh and ps2-cover-art.sh. Sourced,
# never executed — it defines functions and nothing else.
#
# One disc carries one serial in three spellings, and each consumer wants a
# different one:
#
#   SLES_503.86   startup — what SYSTEM.CNF declares and OPL keys ART/ and CFG/ on
#   SLES-50386    the form cover repositories publish
#   SLES_503.86.  the filename prefix OPL strips back off for display
#
# Every spelling is derived from one normalisation, so they cannot drift apart.

# Serial prefixes a PS2 disc can carry. SCE* are Sony-published, SL** licensed.
PS2_SERIAL_PREFIXES="SLUS|SLES|SLPS|SLPM|SCUS|SCES|SCPS|SCPM|SCAJ|SLKA|SCKA|SLAJ"

# SYSTEM.CNF usually sits near the front of the image, so read a slice first and
# only fall back to walking the whole disc when it is not there. Some discs do
# bury it — one 501 MB disc here keeps BOOT2 past the 64 MB mark.
PS2_HEADER_BYTES=$((16 * 1024 * 1024))

read_header() {
    head -c "$PS2_HEADER_BYTES" "$1" 2>/dev/null | strings
}

# Streamed, never captured whole: `strings` over a 4 GB disc would otherwise land
# in a shell variable. `-m1` also lets strings exit early on a hit.
scan_disc() {
    strings "$1" | grep -m1 -oiE "$2"
}

# The first line matching a pattern, cheap slice first and full disc after.
find_in_disc() {
    local iso_file="$1" header="$2" pattern="$3" hit

    hit=$(grep -m1 -oiE "$pattern" <<<"$header")

    if [ -n "$hit" ]; then
        echo "$hit"

        return 0
    fi

    scan_disc "$iso_file" "$pattern"
}

# Any spelling of a serial to the startup form OPL stores. Four prefix letters
# and five digits are the whole serial; the punctuation between them is noise,
# so it is stripped and reinserted rather than parsed.
normalise_serial() {
    local raw prefix digits

    raw=$(tr 'a-z' 'A-Z' <<<"$1" | tr -d '._-' | tr -d '\r\n')
    prefix="${raw:0:4}"
    digits="${raw:4:5}"

    if [ "${#digits}" -ne 5 ] || [[ ! "$prefix" =~ ^[A-Z]{4}$ ]] || [[ ! "$digits" =~ ^[0-9]{5}$ ]]; then
        return 1
    fi

    printf '%s_%s.%s\n' "$prefix" "${digits:0:3}" "${digits:3:2}"
}

# The dashed form cover repositories publish, from a startup. Pure string work,
# so callers never reopen the disc just to get the other spelling.
cover_id_from_startup() {
    local raw

    raw=$(tr -d '._-' <<<"$1")

    printf '%s-%s\n' "${raw:0:4}" "${raw:4:5}"
}

# The serial a filename already carries, in either spelling. Free compared with
# opening a 4 GB image, so callers try this before touching the disc.
startup_from_name() {
    local hit

    hit=$(basename "$1" | grep -oiE "^($PS2_SERIAL_PREFIXES)[-_][0-9]{3}\.?[0-9]{2}") || return 1

    normalise_serial "$hit"
}

# The serial the disc itself declares. BOOT2 in SYSTEM.CNF is authoritative; a
# bare serial anywhere in the image is the fallback for discs whose BOOT2 line
# does not survive `strings`.
startup_from_disc() {
    local iso_file="$1" header="$2" boot2 serial

    boot2=$(find_in_disc "$iso_file" "$header" "BOOT2[[:space:]]*=[[:space:]]*cdrom0:.[^;[:space:]]+")

    if [ -n "$boot2" ]; then
        normalise_serial "$(sed 's/.*cdrom0:.//; s/\.ELF//i' <<<"$boot2")" && return 0
    fi

    # -o matters: without it grep returns the whole line, so a trailing ";1"
    # became part of the ID and yielded SLES-50386-1.
    serial=$(find_in_disc "$iso_file" "$header" "($PS2_SERIAL_PREFIXES)[-_][0-9]{3}\.?[0-9]{2}")

    [ -n "$serial" ] || return 1

    normalise_serial "$serial"
}

# The serial's third and fourth letters carry the region — SC is Sony-published
# and SL is licensed, which says nothing about where it shipped.
#
# Scandinavia and World are deliberately absent: no PS2 disc records them. They
# are No-Intro filename conventions, which is why the app reads region from the
# filename rather than from here.
region_for() {
    case "${1:0:4}" in
        SLUS|SCUS)                echo "USA" ;;
        SLES|SCES)                echo "Europe" ;;
        SLPS|SLPM|SCPS|SCPM|SCAJ) echo "Japan" ;;
        SLKA|SCKA)                echo "Korea" ;;
        SLAJ)                     echo "Asia" ;;
        *)                        echo "Unknown region" ;;
    esac
}

# PAL or NTSC, straight out of SYSTEM.CNF. It corroborates the serial without
# replacing it: NTSC covers both USA and Japan.
video_mode_for() {
    find_in_disc "$1" "$2" "VMODE[[:space:]]*=[[:space:]]*(PAL|NTSC)" \
        | sed 's/.*=[[:space:]]*//' | tr -d '\r\n' | tr 'a-z' 'A-Z'
}
