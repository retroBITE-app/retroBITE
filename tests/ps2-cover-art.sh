#!/bin/bash
#
# Downloads OPL cover art for every PS2 ISO in a directory, into an ART/
# directory beside them.
#
# Usage: ./tests/ps2-cover-art.sh <directory>
#
#   ./tests/ps2-cover-art.sh games/ps2
#   ./tests/ps2-cover-art.sh games/ps2/DVD
#
# OPL keys art on the game's startup ID as SYSTEM.CNF declares it, so the file
# it looks for is ART/SLES_503.86_COV.png — dotted, not SLES_50386_COV.png.
#
# Downloads are re-encoded before they land: see lib/ps2-cover.sh for why art
# straight from a repository is usually too large for the PS2 to draw. Art
# already in ART/ under an older name or format is converted in place rather
# than downloaded again.
#
# The ID is read from the filename when it carries one and out of the disc
# otherwise, so ISOs that were never renamed still get art. Requires `curl`,
# `strings`, and either ImageMagick or Pillow. Art comes from xlenore/ps2-covers,
# which publishes the dashed form.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/ps2-disc.sh"
source "${SCRIPT_DIR}/lib/ps2-cover.sh"

COVER_REPO="https://raw.githubusercontent.com/xlenore/ps2-covers/main/covers/default"

if [ $# -eq 0 ]; then
    echo "Usage: $0 <directory>"
    echo "Example: $0 games/ps2"
    exit 1
fi

SCAN_DIR="$1"
ART_DIR="${SCAN_DIR}/ART"

if [ ! -d "$SCAN_DIR" ]; then
    echo "Error: Directory '$SCAN_DIR' not found!"
    exit 1
fi

command -v curl >/dev/null 2>&1 || { echo "Error: curl is required but not installed."; exit 1; }

if ! cover_converter >/dev/null; then
    echo "Error: ImageMagick or Python Pillow is required to size art for the PS2."
    echo "→ sudo apt install imagemagick   (or: pip install pillow)"
    exit 1
fi

mkdir -p "$ART_DIR"

# The filename first because it costs nothing; the disc only when the name has
# no serial to read. An unrenamed ISO still has art fetched for it.
startup_for_iso() {
    local iso_file="$1" startup

    if startup=$(startup_from_name "$iso_file"); then
        echo "$startup"

        return 0
    fi

    startup_from_disc "$iso_file" "$(read_header "$iso_file")"
}

# Art an earlier run left behind, under any name it used to write: the dotted
# JPEG, and the dot-stripped one OPL never looked for. Re-encoding these is
# cheaper and more reliable than fetching the same bytes again.
existing_cover() {
    local startup="$1" candidate

    for candidate in "${ART_DIR}/${startup}_COV.jpg" "${ART_DIR}/${startup/./}_COV.jpg"; do
        if [ -s "$candidate" ]; then
            echo "$candidate"

            return 0
        fi
    done

    return 1
}

# Fetch into a temp file, so a 404 never leaves a stub in ART/ for OPL to draw
# as a blank.
fetch_cover() {
    local cover_id="$1" dest="$2"

    curl -f -s -L -o "$dest" "${COVER_REPO}/${cover_id}.jpg" 2>/dev/null && [ -s "$dest" ]
}

download_cover() {
    local startup="$1"
    local dest="${ART_DIR}/${startup}_COV.png"
    local cover_id source temp status

    if [ -f "$dest" ]; then
        echo "→ Cover already exists: ${startup}_COV.png"

        return 0
    fi

    if source=$(existing_cover "$startup"); then
        if normalise_cover "$source" "$dest"; then
            rm -f "$source"
            echo "✓ Converted $(basename "$source") → ${startup}_COV.png"

            return 0
        fi

        echo "⚠ Could not convert $(basename "$source")"

        return 1
    fi

    cover_id=$(cover_id_from_startup "$startup")
    temp=$(mktemp)

    if ! fetch_cover "$cover_id" "$temp"; then
        rm -f "$temp"
        echo "⚠ No cover published for $cover_id"

        return 1
    fi

    normalise_cover "$temp" "$dest"
    status=$?
    rm -f "$temp"

    if [ $status -ne 0 ]; then
        rm -f "$dest"
        echo "⚠ Downloaded $cover_id but could not convert it"

        return 1
    fi

    echo "✓ Downloaded ${startup}_COV.png"

    return 0
}

echo "Scanning directory: $SCAN_DIR"
echo "Cover art goes to: $ART_DIR"
echo "Sizing art to ${COVER_WIDTH}x${COVER_HEIGHT} PNG for the PS2's video memory."

ISO_COUNT=0
FOUND=0
MISSING=0
UNNAMED=0

while IFS= read -r -d '' iso_file; do
    ((ISO_COUNT++))

    if ! STARTUP=$(startup_for_iso "$iso_file"); then
        echo "⚠ No game ID in name or disc: $(basename "$iso_file")"
        ((UNNAMED++))
        continue
    fi

    if download_cover "$STARTUP"; then
        ((FOUND++))
    else
        ((MISSING++))
    fi
done < <(find "$SCAN_DIR" -type f -iname "*.iso" -print0)

echo ""

if [ $ISO_COUNT -eq 0 ]; then
    echo "⚠ No ISO files found in $SCAN_DIR"
    echo "→ For PS2/OPL, files should be in DVD/ or CD/ subdirectories"
    exit 0
fi

if [ $UNNAMED -gt 0 ]; then
    echo "→ $UNNAMED file(s) carry no readable game ID — check them with ./tests/ps2-game-ids.sh $SCAN_DIR"
fi

echo "=========================================="
echo "$ISO_COUNT ISO(s): $FOUND with art, $MISSING without"
echo "=========================================="
