#!/bin/bash
#
# Cover-art normalisation for ps2-cover-art.sh. Sourced, never executed.
#
# Art is rewritten rather than saved as downloaded, because the PS2 is stricter
# than any desktop viewer:
#
#   size    OPL uploads the whole cover as one RGBA texture. At 512x736 that is
#           1.5 MB of the console's 4 MB of VRAM, before the frame buffers —
#           oversized art draws nothing at all rather than failing loudly.
#   format  the JPEG decoder is baseline only; progressive files decode to
#           nothing, and repositories publish both.
#   metadata EXIF blocks survive re-encoding and buy nothing on a PS2.
#
# PNG is the output because OPL tries it before jpg and bmp, and it has none of
# the JPEG traps.

# Half the 512x736 the cover repositories publish. Widths must be a multiple of
# 64 for the GS, and 256 is still sharp at the PS2's 640x448 output.
COVER_WIDTH=256
COVER_HEIGHT=368

# ImageMagick if it is here, Pillow otherwise. Both are common enough that
# requiring either would turn a working machine away.
cover_converter() {
    if command -v magick >/dev/null 2>&1; then
        echo "magick"
    elif command -v convert >/dev/null 2>&1; then
        echo "convert"
    elif python3 -c "import PIL" >/dev/null 2>&1; then
        echo "pillow"
    else
        return 1
    fi
}

# One cover to the shape OPL can actually draw. Leaves the source alone; the
# caller owns it.
normalise_cover() {
    local src="$1" dest="$2"

    case "$(cover_converter)" in
        magick)
            magick "$src" -strip -resize "${COVER_WIDTH}x${COVER_HEIGHT}!" "PNG24:${dest}" 2>/dev/null
            ;;
        convert)
            convert "$src" -strip -resize "${COVER_WIDTH}x${COVER_HEIGHT}!" "PNG24:${dest}" 2>/dev/null
            ;;
        pillow)
            python3 - "$src" "$dest" "$COVER_WIDTH" "$COVER_HEIGHT" <<'PY' 2>/dev/null
import sys
from PIL import Image

src, dest, width, height = sys.argv[1], sys.argv[2], int(sys.argv[3]), int(sys.argv[4])

with Image.open(src) as image:
    image.convert("RGB").resize((width, height), Image.LANCZOS).save(dest, "PNG", optimize=True)
PY
            ;;
        *)
            return 2
            ;;
    esac

    [ -s "$dest" ]
}
