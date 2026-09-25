<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ImageFormat;
use GdImage;
use RuntimeException;

/**
 * Re-encode a piece of cached artwork into the exact shape a loader wants.
 *
 * Open PS2 Loader reads ART/<serial>_COV at 256x368, opaque — the size every
 * file on a working drive turns out to be. The provider's box art is whatever
 * size it was scanned at, so something has to do this, and GD is in the runtime
 * image while ImageMagick is not.
 *
 * JPEG by default, as the smaller file for a loader that reads it. OPL does
 * not: the 1.2 builds carry libpng and no JPEG decoder at all, and a _COV.jpg
 * is simply never drawn — so everything written for OPL asks for PNG.
 *
 * Scaling is cover-and-crop rather than fit-and-pad. Box art runs about 5:7
 * against OPL's 0.696, so the crop takes a few pixels off the sides; a padded
 * cover with bars down it reads as a mistake on a shelf of full-bleed ones.
 */
final class CoverArt
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly ImageFormat $format = ImageFormat::Jpeg,
        public readonly int $quality = 90,
        // Keep the source's transparency instead of flattening it: for a shape
        // that is not a rectangle, like the round disc OPL draws as an icon.
        public readonly bool $transparent = false,
    ) {
        if ($width < 1 || $height < 1) {
            throw new RuntimeException('A cover has to have a size.');
        }

        // Refused here rather than passed to GD, which silently treats an
        // out-of-range quality as its own default and gives no sign of it.
        if ($quality < 0 || $quality > 100) {
            throw new RuntimeException('A quality has to be between 0 and 100.');
        }

        if ($transparent && $format !== ImageFormat::Png) {
            throw new RuntimeException('Only a PNG can keep transparency.');
        }
    }

    /**
     * The source rectangle to take, centred, so the result fills the frame.
     *
     * @return array{x: int, y: int, width: int, height: int}
     */
    public function cropFor(int $sourceWidth, int $sourceHeight): array
    {
        // A zero-sided image cannot be cropped into anything. Guarded rather
        // than divided by, because GD will hand one over for a truncated file.
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            throw new RuntimeException('Cannot crop an image with no area.');
        }

        $wanted = $this->width / $this->height;
        $have = $sourceWidth / $sourceHeight;

        if ($have > $wanted) {
            // Too wide: keep the full height and take a centred column.
            $cropWidth = (int) round($sourceHeight * $wanted);
            $cropHeight = $sourceHeight;
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int) round($sourceWidth / $wanted);
        }

        // Rounding can overshoot by a pixel on an image that is already the
        // right shape, and GD reads past the edge rather than clamping.
        $cropWidth = min($cropWidth, $sourceWidth);
        $cropHeight = min($cropHeight, $sourceHeight);

        return [
            'x' => (int) max(0, floor(($sourceWidth - $cropWidth) / 2)),
            'y' => (int) max(0, floor(($sourceHeight - $cropHeight) / 2)),
            'width' => max(1, $cropWidth),
            'height' => max(1, $cropHeight),
        ];
    }

    /**
     * This format's bytes at this size, from whatever was handed in.
     *
     * @param  string  $contents  a PNG, JPEG or WebP as it came off the disk
     *
     * @throws RuntimeException when GD cannot read it
     */
    public function encode(string $contents): string
    {
        // GD announces an unreadable file twice: a warning, and false. The
        // warning is the awkward one — @ does not reach it under a handler
        // that ignores the suppression operator, and a truncated cover would
        // otherwise surface as a PHP warning in a queue worker's log rather
        // than as the skip it is. Silenced for exactly one call.
        set_error_handler(static function (): bool {
            return true;
        });

        try {
            $source = imagecreatefromstring($contents);
        } finally {
            restore_error_handler();
        }

        if (! $source instanceof GdImage) {
            throw new RuntimeException('Not an image GD can read.');
        }

        // max() rather than the properties directly: the constructor already
        // refused anything smaller, but a readonly int does not carry that.
        $width = max(1, $this->width);
        $height = max(1, $this->height);

        $target = imagecreatetruecolor($width, $height);

        try {
            // Composited onto opaque black for a cover: OPL draws no alpha on
            // one, and a transparent source arrives on the console full of
            // whatever was in that memory. JPEG has no alpha at all, so this
            // matters more under the default format rather than less. A
            // transparent frame starts clear and is copied into without
            // blending, so the source's own alpha is what lands.
            imagealphablending($target, ! $this->transparent);
            imagefilledrectangle(
                $target, 0, 0, $width - 1, $height - 1,
                $this->transparent
                    ? (int) imagecolorallocatealpha($target, 0, 0, 0, 127)
                    : (int) imagecolorallocate($target, 0, 0, 0),
            );

            $crop = $this->cropFor((int) imagesx($source), (int) imagesy($source));

            // Resampled, not resized: the plain copy aliases badly at the
            // reduction a 1400px scan needs to reach 368.
            imagecopyresampled(
                $target, $source,
                0, 0,
                $crop['x'], $crop['y'],
                $width, $height,
                $crop['width'], $crop['height'],
            );

            imagesavealpha($target, $this->transparent);

            ob_start();

            match ($this->format) {
                ImageFormat::Jpeg => imagejpeg($target, null, $this->quality),
                ImageFormat::Png => imagepng($target, null, 6),
            };

            return (string) ob_get_clean();
        } finally {
            // Dropped rather than imagedestroy()'d, which PHP 8.4 deprecated:
            // a GdImage is an object now and its memory goes with the last
            // reference. Worth being explicit anyway — a 4000px source held
            // across five hundred games is how this runs a worker out of it.
            unset($source, $target);
        }
    }
}
