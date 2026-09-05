#!/bin/bash
#
# Reads the game ID out of every PS2 ISO in a directory. OPL matches on that ID
# being in the filename, so the script can also put it there.
#
# Usage: ./tests/ps2-game-ids.sh [--rename] <directory>
#
# Report what each disc is and what it would be renamed to, touching nothing:
#
#   ./tests/ps2-game-ids.sh games/ps2
#
# Actually rename. This rewrites filenames in place and cannot be undone:
#
#   ./tests/ps2-game-ids.sh --rename games/ps2
#   ./tests/ps2-game-ids.sh --rename games/ps2/DVD
#
# Each disc reports its ID, the region its serial encodes, and the video mode
# from SYSTEM.CNF:
#
#   ✓ Game ID found: SLES-53777 · Europe (PAL)
#
# Region comes from the serial's prefix, which is the only region marker a PS2
# disc carries. Scandinavia and World are absent on purpose — no disc records
# them; they are filename conventions, which is where the app reads them from.
#
# Requires `strings` (binutils); it makes no network calls.

RENAME=0
SCAN_DIR=""

while [ $# -gt 0 ]; do
    case "$1" in
        --rename|-rename) RENAME=1; shift ;;
        -h|--help)
            awk 'NR > 2 && /^#/ { sub(/^# ?/, ""); print; next } NR > 2 { exit }' "${BASH_SOURCE[0]}"
            exit 0
            ;;
        *) SCAN_DIR="$1"; shift ;;
    esac
done

if [ -z "$SCAN_DIR" ]; then
    echo "Usage: $0 [--rename] <directory>"
    echo "Example: $0 --rename games/ps2"
    exit 1
fi

if [ ! -d "$SCAN_DIR" ]; then
    echo "Error: Directory '$SCAN_DIR' not found!"
    exit 1
fi

# SYSTEM.CNF usually sits near the front of the image, so read a slice first and
# only fall back to walking the whole disc when it is not there. Some discs do
# bury it — one 501 MB disc here keeps BOOT2 past the 64 MB mark.
HEADER_BYTES=$((16 * 1024 * 1024))

read_header() {
    head -c "$HEADER_BYTES" "$1" 2>/dev/null | strings
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

# The ID as OPL writes it, e.g. SLUS-20062. Two sources: SYSTEM.CNF's BOOT2 line
# is authoritative, and a bare serial anywhere in the image is the fallback for
# discs whose BOOT2 line does not survive `strings`.
game_id_for() {
    local iso_file="$1" header="$2" boot2 serial

    boot2=$(find_in_disc "$iso_file" "$header" "BOOT2[[:space:]]*=[[:space:]]*cdrom0:.[^;[:space:]]+")

    if [ -n "$boot2" ]; then
        echo "$boot2" | sed 's/.*cdrom0:.//' | sed 's/[_;]/-/g; s/\.ELF//i; s/\.//g' | tr -d '\r\n'

        return 0
    fi

    # -o matters: without it grep returns the whole line, so a trailing ";1"
    # became part of the ID and yielded SLES-50386-1.
    serial=$(find_in_disc "$iso_file" "$header" \
        "(SLUS|SLES|SLPS|SLPM|SCUS|SCES|SCPS|SCPM|SLKA|SCKA|SLAJ)[-_][0-9]{3}\\.?[0-9]{2}")

    if [ -n "$serial" ]; then
        echo "$serial" | sed 's/[_;]/-/g; s/\.//g' | tr -d '\r\n'

        return 0
    fi

    return 1
}

# The serial's third and fourth letters carry the region — SC is Sony-published
# and SL is licensed, which says nothing about where it shipped.
#
# Scandinavia and World are deliberately absent: no PS2 disc records them. They
# are No-Intro filename conventions, which is why the app reads region from the
# filename rather than from here.
region_for() {
    case "${1:0:4}" in
        SLUS|SCUS)                     echo "USA" ;;
        SLES|SCES)                     echo "Europe" ;;
        SLPS|SLPM|SCPS|SCPM|SCAJ)      echo "Japan" ;;
        SLKA|SCKA)                     echo "Korea" ;;
        SLAJ)                          echo "Asia" ;;
        *)                             echo "Unknown region" ;;
    esac
}

# PAL or NTSC, straight out of SYSTEM.CNF. It corroborates the serial without
# replacing it: NTSC covers both USA and Japan.
video_mode_for() {
    find_in_disc "$1" "$2" "VMODE[[:space:]]*=[[:space:]]*(PAL|NTSC)" \
        | sed 's/.*=[[:space:]]*//' | tr -d '\r\n' | tr 'a-z' 'A-Z'
}

# Prefix the filename with its ID, unless it already carries one. Renames only
# when --rename was given; otherwise it just says what it would do.
rename_to_id() {
    local iso_file="$1" game_id="$2"
    local current_name ext basename suggested dir_path

    current_name=$(basename "$iso_file")

    if [[ "$current_name" == "${game_id}."* ]]; then
        echo "✓ File already has game ID in name: $current_name"

        return 0
    fi

    ext="${current_name##*.}"
    basename="${current_name%.*}"
    suggested="${game_id}.${basename}.${ext}"
    dir_path=$(dirname "$iso_file")

    if [ "$RENAME" -eq 0 ]; then
        echo "→ Would rename to: $suggested"

        return 0
    fi

    echo "→ Current: $current_name"
    echo "→ Renaming to: $suggested"

    if mv "$iso_file" "${dir_path}/${suggested}"; then
        echo "✓ Successfully renamed!"

        return 0
    fi

    echo "✗ Failed to rename file"

    return 1
}

process_iso() {
    local iso_file="$1" header game_id region vmode

    echo ""
    echo "=========================================="
    echo "Analyzing: $(basename "$iso_file")"
    echo "=========================================="

    header=$(read_header "$iso_file")

    if ! game_id=$(game_id_for "$iso_file" "$header"); then
        echo "✗ Unable to determine game ID"
        echo "→ You may need to look it up manually"

        return 1
    fi

    region=$(region_for "$game_id")
    vmode=$(video_mode_for "$iso_file" "$header")

    if [ -n "$vmode" ]; then
        echo "✓ Game ID found: $game_id · $region ($vmode)"
    else
        echo "✓ Game ID found: $game_id · $region"
    fi

    rename_to_id "$iso_file" "$game_id"
}

echo "Scanning directory: $SCAN_DIR"
echo "Looking for ISO files (including subdirectories)..."
[ "$RENAME" -eq 0 ] && echo "Reporting only — pass --rename to write the IDs into filenames." 

ISO_COUNT=0
while IFS= read -r -d '' iso_file; do
    process_iso "$iso_file"
    ((ISO_COUNT++))
done < <(find "$SCAN_DIR" -type f -iname "*.iso" -print0)

if [ $ISO_COUNT -eq 0 ]; then
    echo ""
    echo "⚠ No ISO files found in $SCAN_DIR"
    echo "→ Make sure you have copied your game files to this directory"
    echo "→ For PS2/OPL, files should be in DVD/ or CD/ subdirectories"
fi

echo ""
echo "=========================================="
echo "Scan complete. Found $ISO_COUNT ISO file(s)"
echo "=========================================="
