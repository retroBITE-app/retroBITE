#!/bin/bash
#
# Reads the game ID out of every PS2 ISO in a directory. OPL can also match on
# the ID being in the filename, so the script can put it there.
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
#   ✓ Game ID found: SLES_503.86 · Europe (PAL)
#
# The ID is written in the dotted form SYSTEM.CNF declares, which is the one OPL
# stores as the game's startup and keys ART/ and CFG/ on. A filename already
# carrying a serial is rewritten to that spelling rather than prefixed again.
#
# Region comes from the serial's prefix, which is the only region marker a PS2
# disc carries. Scandinavia and World are absent on purpose — no disc records
# them; they are filename conventions, which is where the app reads them from.
#
# Requires `strings` (binutils); it makes no network calls.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/ps2-disc.sh"

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

# Every serial already at the head of the name, however it is spelled. Repeated
# because an earlier run of this script prefixed names that were already
# prefixed, leaving SLES-50386.SLES_503.86.Crash….iso on disk.
strip_leading_serials() {
    local name="$1"

    while [[ "$name" =~ ^($PS2_SERIAL_PREFIXES)[-_][0-9]{3}\.?[0-9]{2}\. ]]; do
        name="${name#"${BASH_REMATCH[0]}"}"
    done

    echo "$name"
}

# Prefix the filename with its startup ID, unless it already carries exactly
# that. Renames only when --rename was given; otherwise it says what it would do.
rename_to_id() {
    local iso_file="$1" startup="$2"
    local current_name stripped suggested dir_path

    current_name=$(basename "$iso_file")
    stripped=$(strip_leading_serials "$current_name")
    suggested="${startup}.${stripped}"

    if [ "$current_name" = "$suggested" ]; then
        echo "✓ File already has game ID in name: $current_name"

        return 0
    fi

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
    local iso_file="$1" header startup region vmode

    echo ""
    echo "=========================================="
    echo "Analyzing: $(basename "$iso_file")"
    echo "=========================================="

    header=$(read_header "$iso_file")

    if ! startup=$(startup_from_disc "$iso_file" "$header"); then
        echo "✗ Unable to determine game ID"
        echo "→ You may need to look it up manually"

        return 1
    fi

    region=$(region_for "$startup")
    vmode=$(video_mode_for "$iso_file" "$header")

    if [ -n "$vmode" ]; then
        echo "✓ Game ID found: $startup · $region ($vmode)"
    else
        echo "✓ Game ID found: $startup · $region"
    fi

    rename_to_id "$iso_file" "$startup"
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
