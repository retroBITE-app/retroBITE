#!/bin/bash
#
# retroBITE — PlayStation 2 disc inspection
# https://github.com/mattiasghodsian/retroBite
#
# Part of retroBITE and written for it. Invoked by App\Tools\ConsoleTool\PS2
# through Illuminate\Support\Facades\Process; not meant to be run by hand,
# though it works standalone and that is useful when something is wrong.
#
# Usage: Inspect.sh <disc image>
#
# Writes tab-separated key/value lines on stdout and nothing else. Progress and
# complaints go to stderr, so a caller can parse stdout whole.
#
#   license_id	SLES_503.86
#   cover_id	SLES-50386
#   region	Europe
#   video_mode	PAL
#
# Exit 0  something was found
#      1  nothing to find here — a compressed image, or a disc with no serial.
#         An ordinary answer, not a failure.
#      2  called wrongly, or the file cannot be read
#
# This exists because doing it in PHP means parsing ISO9660 by hand, while
# `strings` over the first 16 MiB answers in a line.
#
# One disc carries one serial in three spellings, and each consumer wants a
# different one:
#
#   SLES_503.86   startup — what SYSTEM.CNF declares and OPL keys ART/ and CFG/ on
#   SLES-50386    the form cover repositories publish
#   SLES_503.86.  the filename prefix OPL strips back off for display
#
# Every spelling is derived from one normalisation, so they cannot drift apart.

set -u

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
# opening a 4 GB image, so this is tried before touching the disc.
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

# Whether this is a compressed container rather than a raw disc.
#
# By magic number rather than by extension: the caller has already checked the
# extension against the console's config, so repeating that here would be the
# same check twice. What this catches is the case the extension cannot — a CSO
# somebody renamed to .iso, which is common enough on these drives. Nothing the
# rest of this script looks for survives the compression, so scanning one is
# four gigabytes spent learning nothing.
is_compressed() {
    local magic

    magic=$(head -c 8 "$1" 2>/dev/null)

    case "$magic" in
        CISO*)     return 0 ;;  # .cso
        ZISO*)     return 0 ;;  # .zso
        MComprHD*) return 0 ;;  # .chd
        *)         return 1 ;;
    esac
}

iso_file="${1:-}"

if [ -z "$iso_file" ]; then
    echo "Usage: $(basename "$0") <disc image>" >&2

    exit 2
fi

if [ ! -r "$iso_file" ]; then
    echo "Cannot read: ${iso_file}" >&2

    exit 2
fi

if is_compressed "$iso_file"; then
    echo "Compressed image, nothing to read inside: ${iso_file}" >&2

    exit 1
fi

# The filename first: it costs nothing, and on an OPL drive it is already there.
# Only a disc that does not say so in its name is opened.
startup=$(startup_from_name "$iso_file") || startup=""

if [ -z "$startup" ]; then
    header=$(read_header "$iso_file")
    startup=$(startup_from_disc "$iso_file" "$header") || startup=""
else
    header=""
fi

if [ -z "$startup" ]; then
    echo "No PS2 serial found in: ${iso_file}" >&2

    exit 1
fi

printf 'license_id\t%s\n' "$startup"
printf 'cover_id\t%s\n' "$(cover_id_from_startup "$startup")"
printf 'region\t%s\n' "$(region_for "$startup")"

# Only worth a full scan when the header was already read for the serial. A
# disc named after its serial keeps its video mode to itself rather than
# earning a second pass over four gigabytes for one optional field.
if [ -n "$header" ]; then
    video_mode=$(video_mode_for "$iso_file" "$header")

    if [ -n "$video_mode" ]; then
        printf 'video_mode\t%s\n' "$video_mode"
    fi
fi

exit 0
