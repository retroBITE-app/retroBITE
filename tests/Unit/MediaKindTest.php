<?php

use App\Enums\MediaKind;

/**
 * The reverse factory turns a provider media type back into the slot it fills,
 * which is how a cached row gets a name a reader recognises. Its fallback is
 * the interesting half: the provider holds far more types than we have slots.
 */
it('maps every provider type back to the slot it fills', function (string $type, MediaKind $kind) {
    expect(MediaKind::fromScreenScraperType($type))->toBe($kind);
})->with([
    ['box-2D', MediaKind::Cover],
    ['box-3D', MediaKind::Cover],
    ['wheel', MediaKind::Logo],
    ['wheel-carbon', MediaKind::Logo],
    ['fanart', MediaKind::Backdrop],
    ['support-2D', MediaKind::Disc],
    // A backdrop's stand-ins are named for what they are.
    ['ss', MediaKind::Screenshot],
    ['sstitle', MediaKind::TitleScreen],
    ['screenmarquee', MediaKind::Backdrop],
]);

it('answers null for a type no slot holds', function (string $type) {
    // Not a failure: the caller shows the raw type it was given instead.
    expect(MediaKind::fromScreenScraperType($type))->toBeNull();
})->with([
    ['video'],
    ['manuel'],
    [''],
]);

it('matches the provider casing exactly', function () {
    // box-2D is how ScreenScraper spells it and how the column stores it. A
    // drift degrades to the raw-type fallback rather than mislabelling.
    expect(MediaKind::fromScreenScraperType('box-2d'))->toBeNull();
});
