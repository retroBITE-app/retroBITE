#!/bin/bash
#
# Writes OPL's per-game info files, CFG/<startup>.cfg, for every PS2 ISO in a
# directory. Without them the Info page (Square on a game) shows only the size,
# which OPL computes itself.
#
# Usage: ./tests/ps2-game-info.sh [--force] <directory>
#
#   ./tests/ps2-game-info.sh games/ps2
#   ./tests/ps2-game-info.sh --force games/ps2      # refetch games already written
#
# The keys come from OPL's built-in theme, which reads them by the names its
# Info page elements declare — Title, Genre, Release, Developer, Description and
# Rating, unprefixed. The hash-prefixed keys in the same file (#Size, #Media,
# #Format) are OPL's own and are computed from the disc, so they are never
# written here.
#
# Metadata comes from ScreenScraper through the app's own service — see
# lib/ps2-metadata.php. Each disc is matched by md5, which is exact; a name
# search is only the fallback, and it is a guess — ScreenScraper answers
# "Ratchet & Clank" with Ratchet And Clank 3. Hashing the whole library costs
# well under a minute.
#
# Requires `php` with the web/ dependencies installed (composer install),
# `md5sum`, and a network connection.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/ps2-disc.sh"

CONSOLE_KEY="ps2"

# The Info page wraps Description into a 574x80 box. A synopsis runs to several
# hundred words, and the overflow is not scrollable — it is simply not drawn.
DESCRIPTION_MAX=300

FORCE=0
SCAN_DIR=""

while [ $# -gt 0 ]; do
    case "$1" in
        --force|-f) FORCE=1; shift ;;
        -h|--help)
            awk 'NR > 2 && /^#/ { sub(/^# ?/, ""); print; next } NR > 2 { exit }' "${BASH_SOURCE[0]}"
            exit 0
            ;;
        *) SCAN_DIR="$1"; shift ;;
    esac
done

if [ -z "$SCAN_DIR" ]; then
    echo "Usage: $0 [--force] <directory>"
    echo "Example: $0 games/ps2"
    exit 1
fi

if [ ! -d "$SCAN_DIR" ]; then
    echo "Error: Directory '$SCAN_DIR' not found!"
    exit 1
fi

command -v php >/dev/null 2>&1 || { echo "Error: php is required but not installed."; exit 1; }
command -v md5sum >/dev/null 2>&1 || { echo "Error: md5sum is required but not installed."; exit 1; }

CFG_DIR="${SCAN_DIR}/CFG"
mkdir -p "$CFG_DIR"

# The title to search on: the disc's name without the serial prefix these
# scripts add, and without the No-Intro region and revision tags after it. The
# trailing article is put back in front, because No-Intro writes "Simpsons, The"
# and no provider indexes it that way.
search_name_for() {
    basename "$1" \
        | sed -E "s/\.[^.]+$//" \
        | sed -E "s/^($PS2_SERIAL_PREFIXES)[-_][0-9]{3}\.?[0-9]{2}\.//I" \
        | sed -E "s/[[(][^])]*[])]//g; s/[[:space:]]+/ /g; s/^ //; s/ $//" \
        | sed -E "s/^(.*), (The|A|An)( - .*)?$/\2 \1\3/"
}

# ScreenScraper is regularly slow under load, and a dropped request is
# indistinguishable from a miss. One retry turns most of those back into hits.
lookup_metadata() {
    local hash="$1" name="$2" dest="$3" attempt

    for attempt in 1 2; do
        php "${SCRIPT_DIR}/lib/ps2-metadata.php" "--md5=${hash}" "$CONSOLE_KEY" "$name" > "$dest" 2>/dev/null && return 0

        [ "$attempt" -eq 1 ] && sleep 2
    done

    return 1
}

# The disc's md5. Exact where ScreenScraper has the hash, which for PS2 is most
# of Redump — and unlike the filename it cannot name the wrong sequel.
md5_for() {
    md5sum "$1" | cut -d' ' -f1
}

# Cut to the last whole word inside the limit. Trailing punctuation from the cut
# would read as a typo, so it goes too.
truncate_text() {
    local text="$1" limit="$2"

    [ "${#text}" -le "$limit" ] && { echo "$text"; return 0; }

    text="${text:0:$limit}"
    text="${text% *}"

    echo "$(sed -E 's/[[:punct:]]+$//' <<<"$text")..."
}

# OPL writes the player's own per-game settings — $DMA, $VMC, $Compatibility and
# the rest — into this same file. Regenerating carries them across; replacing
# the file wholesale would silently reset every game's configuration.
preserved_settings() {
    [ -f "$1" ] || return 0

    grep -E '^\$' "$1" 2>/dev/null
}

# Skip a game already described, so a rerun costs no requests. --force refetches.
already_written() {
    [ "$FORCE" -eq 0 ] && [ -f "$1" ] && grep -qE '^Title=' "$1" 2>/dev/null
}

write_cfg() {
    local startup="$1" metadata="$2"
    local -A meta=()
    local dest key value settings

    # Separate statements on purpose: within one `local`, a later assignment
    # cannot read an earlier one, and every game silently wrote to ".cfg".
    dest="${CFG_DIR}/${startup}.cfg"

    while IFS=$'\t' read -r key value; do
        [ -n "$key" ] && meta["$key"]="$value"
    done < "$metadata"

    settings=$(preserved_settings "$dest")

    {
        [ -n "${meta[title]}" ]        && echo "Title=${meta[title]}"
        [ -n "${meta[genre]}" ]        && echo "Genre=${meta[genre]}"
        [ -n "${meta[release_date]}" ] && echo "Release=${meta[release_date]}"
        [ -n "${meta[developer]}" ]    && echo "Developer=${meta[developer]}"
        [ -n "${meta[description]}" ]  && echo "Description=$(truncate_text "${meta[description]}" "$DESCRIPTION_MAX")"
        [ -n "${meta[rating]}" ]       && echo "Rating=${meta[rating]}"
        [ -n "${meta[title]}" ]        && echo "#LongName=${meta[title]}"
        [ -n "$settings" ]             && echo "$settings"
    } > "$dest"

    return 0
}

echo "Scanning directory: $SCAN_DIR"
echo "Game info goes to: $CFG_DIR"
[ "$FORCE" -eq 1 ] && echo "Refetching games that already have a CFG file."

ISO_COUNT=0
WRITTEN=0
SKIPPED=0
MISSED=0

while IFS= read -r -d '' iso_file; do
    ((ISO_COUNT++))

    if ! STARTUP=$(startup_from_name "$iso_file") \
        && ! STARTUP=$(startup_from_disc "$iso_file" "$(read_header "$iso_file")"); then
        echo "⚠ No game ID in name or disc: $(basename "$iso_file")"
        ((MISSED++))
        continue
    fi

    if already_written "${CFG_DIR}/${STARTUP}.cfg"; then
        echo "→ Info already written: ${STARTUP}.cfg"
        ((SKIPPED++))
        continue
    fi

    NAME=$(search_name_for "$iso_file")
    METADATA=$(mktemp)

    echo "→ Hashing $(basename "$iso_file")"
    HASH=$(md5_for "$iso_file")

    if lookup_metadata "$HASH" "$NAME" "$METADATA"; then
        write_cfg "$STARTUP" "$METADATA"
        echo "✓ ${STARTUP}.cfg · $(grep -m1 '^title' "$METADATA" | cut -f2-)"
        ((WRITTEN++))
    else
        echo "⚠ No ScreenScraper match for: $NAME"
        ((MISSED++))
    fi

    rm -f "$METADATA"
done < <(find "$SCAN_DIR" -type f -iname "*.iso" -print0)

echo ""

if [ $ISO_COUNT -eq 0 ]; then
    echo "⚠ No ISO files found in $SCAN_DIR"
    echo "→ For PS2/OPL, files should be in DVD/ or CD/ subdirectories"
    exit 0
fi

echo "=========================================="
echo "$ISO_COUNT ISO(s): $WRITTEN written, $SKIPPED already done, $MISSED without info"
echo "=========================================="
