#!/bin/bash
#
# Downloads OPL cover art for every PS2 ISO whose filename carries a game ID,
# into an ART/ directory beside them.
#
# Usage: ./tests/ps2-cover-art.sh <directory>
#
#   ./tests/ps2-cover-art.sh games/ps2
#   ./tests/ps2-cover-art.sh games/ps2/DVD
#
# IDs are read from the filenames, so run ./tests/ps2-game-ids.sh first — it is
# what puts them there. Requires `curl`. Art comes from xlenore/ps2-covers.

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

mkdir -p "$ART_DIR"

# The ID a filename already carries, normalised to the SLUS-20062 form the
# cover repository publishes. Accepts the raw disc spelling too — real libraries
# hold both "SLES-50386.name.iso" and "SLES_503.86.name.iso".
id_from_name() {
    basename "$1" \
        | grep -oiE "^(SLUS|SLES|SLPS|SLPM|SCUS|SCES|SCPS|SCPM)[-_][0-9]{3}\.?[0-9]{2}" \
        | tr 'a-z_' 'A-Z-' \
        | tr -d '.'
}

# OPL wants the underscore form for the file it reads, while the repository
# publishes the dash form.
download_cover() {
    local game_id="$1"
    local opl_name="${game_id/-/_}_COV.jpg"
    local dest="${ART_DIR}/${opl_name}"

    if [ -f "$dest" ]; then
        echo "→ Cover already exists: $opl_name"

        return 0
    fi

    if curl -f -s -L -o "$dest" "${COVER_REPO}/${game_id}.jpg" 2>/dev/null && [ -s "$dest" ]; then
        echo "✓ Downloaded $opl_name"

        return 0
    fi

    # A 404 still leaves an empty file behind, which OPL would render as a blank.
    rm -f "$dest"
    echo "⚠ No cover published for $game_id"

    return 1
}

echo "Scanning directory: $SCAN_DIR"
echo "Cover art goes to: $ART_DIR"

ISO_COUNT=0
FOUND=0
MISSING=0
UNNAMED=0

while IFS= read -r -d '' iso_file; do
    ((ISO_COUNT++))
    GAME_ID=$(id_from_name "$iso_file")

    if [ -z "$GAME_ID" ]; then
        echo "⚠ No game ID in name: $(basename "$iso_file")"
        ((UNNAMED++))
        continue
    fi

    if download_cover "$GAME_ID"; then
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
    echo "→ $UNNAMED file(s) carry no game ID — run ./tests/ps2-game-ids.sh $SCAN_DIR first"
fi

echo "=========================================="
echo "$ISO_COUNT ISO(s): $FOUND with art, $MISSING without"
echo "=========================================="
