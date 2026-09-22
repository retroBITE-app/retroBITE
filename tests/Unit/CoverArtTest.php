<?php

use App\Enums\ImageFormat;
use App\Support\CoverArt;

/**
 * Open PS2 Loader reads one size and one format, and a cover that arrives in
 * some other shape is not shown letterboxed — it is not shown at all, or it is
 * shown with whatever was in that memory behind it.
 *
 * Geometry only here: the crop is centred and full-bleed, whatever the source
 * was scanned at. ArtExportTest covers the bytes that come out.
 */
function opl(): CoverArt
{
    return new CoverArt(256, 368);
}

it('takes a centred column from a source that is too wide', function () {
    // 5:7 box art against OPL's 0.696 — a few pixels off each side.
    $crop = opl()->cropFor(1000, 1400);

    expect($crop['height'])->toBe(1400)
        ->and($crop['width'])->toBe(974)
        ->and($crop['x'])->toBe(13)
        ->and($crop['y'])->toBe(0);
});

it('takes a centred band from a source that is too tall', function () {
    $crop = opl()->cropFor(600, 1400);

    expect($crop['width'])->toBe(600)
        ->and($crop['height'])->toBe(863)
        ->and($crop['x'])->toBe(0)
        ->and($crop['y'])->toBe(268);
});

it('takes a square source down the middle', function () {
    $crop = opl()->cropFor(600, 600);

    expect($crop['width'])->toBe(417)
        ->and($crop['height'])->toBe(600)
        ->and($crop['x'])->toBe(91);
});

it('never asks for more than the source has', function (int $width, int $height) {
    $crop = opl()->cropFor($width, $height);

    // Rounding can overshoot by a pixel on an image already the right shape,
    // and GD reads past the edge rather than clamping.
    expect($crop['width'])->toBeLessThanOrEqual($width)
        ->and($crop['height'])->toBeLessThanOrEqual($height)
        ->and($crop['width'])->toBeGreaterThan(0)
        ->and($crop['height'])->toBeGreaterThan(0)
        ->and($crop['x'])->toBeGreaterThanOrEqual(0)
        ->and($crop['y'])->toBeGreaterThanOrEqual(0);
})->with([
    [256, 368],
    [1, 1],
    [1, 4000],
    [4000, 1],
    [255, 367],
]);

it('refuses an image with no area rather than dividing by it', function () {
    expect(function (): void {
        opl()->cropFor(0, 368);
    })->toThrow(RuntimeException::class);
});

it('refuses something that is not an image', function () {
    expect(function (): void {
        opl()->encode('not a png');
    })->toThrow(RuntimeException::class);
});

it('refuses a quality GD would silently ignore', function () {
    // GD treats an out-of-range quality as its own default and says nothing,
    // so a typo would quietly write the whole library at the wrong setting.
    expect(function (): void {
        new CoverArt(256, 368, ImageFormat::Jpeg, 140);
    })->toThrow(RuntimeException::class);
});

it('encodes jpeg unless told otherwise', function () {
    $encoded = opl()->encode(flatPng(500, 700));

    expect(getimagesizefromstring($encoded)['mime'])->toBe(ImageFormat::Jpeg->mime());
});

it('still encodes png when a loader needs one', function () {
    $encoded = (new CoverArt(256, 368, ImageFormat::Png))->encode(flatPng(500, 700));

    expect(getimagesizefromstring($encoded)['mime'])->toBe(ImageFormat::Png->mime());
});

/** A plain PNG to feed the encoder, so these stay free of the filesystem. */
function flatPng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}
