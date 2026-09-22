<?php

use App\Support\Layouts\CustomLayout;
use App\Support\Layouts\OplLayout;
use App\Support\Layouts\RetroArchLayout;
use App\Tools\ConsoleTool\PS2;

/**
 * The other half of the seam ConsoleLayoutTest describes.
 *
 * SLES_503.86 is a PlayStation 2 disc serial; putting it at the front of a
 * filename is Open PS2 Loader's idea. Neither the console nor the layout owns
 * that convention alone, so the console's toolbox reads it and only agrees to
 * do so when the layout is OPL's. The same file therefore scans differently
 * per layout and identically per console, which is what these assert.
 */
it('reads a serial off a filename in either spelling', function (string $filename, ?string $serial) {
    expect((new PS2)->serialFrom($filename))->toBe($serial);
})->with([
    ['SLES_503.86.Tekken Tag Tournament.iso', 'SLES_503.86'],
    // The dashed form cover repositories publish.
    ['SLES-50386.Game.iso', 'SLES_503.86'],
    // Undotted, as some dumps spell it.
    ['SLUS_20576.Harry Potter.iso', 'SLUS_205.76'],
    ['slus_205.76.harry potter.iso', 'SLUS_205.76'],
    // A full path: only the filename is read.
    ['DVD/SCES_509.16.RATCHET CLANK.iso', 'SCES_509.16'],
]);

it('does not see a serial where there is none', function (string $filename) {
    expect((new PS2)->serialFrom($filename))->toBeNull();
})->with([
    // The case the anchoring exists for: four letters and a word boundary are
    // not enough, or every game starting with "serial" would lose its name.
    ['Serial Experiments Lain.iso'],
    ['Castlevania (Europe) (En,Fr,De,Es,It).iso'],
    // Right shape, not a prefix Sony issues.
    ['ABCD_123.45.Game.iso'],
    // No separator between prefix and digits.
    ['SLES503.86.Game.iso'],
    // Too few digits.
    ['SLES_503.8.Game.iso'],
]);

it('strips the prefix under opl and stays out of it everywhere else', function () {
    $tools = new PS2;
    $path = 'DVD/SLES_503.86.Tekken Tag Tournament.iso';

    expect($tools->titleFor(new OplLayout, $path))->toBe('Tekken Tag Tournament')
        // Null is "no opinion", and the layout's own reading stands. A PS2 ISO
        // named this way on a RetroArch drive is a file called exactly that.
        ->and($tools->titleFor(new RetroArchLayout, $path))->toBeNull()
        ->and($tools->titleFor(new CustomLayout, $path))->toBeNull();
});

it('leaves a title alone when the prefix is not one', function () {
    expect((new PS2)->titleFor(new OplLayout, 'DVD/Serial Experiments Lain.iso'))
        ->toBe('Serial Experiments Lain');
});

it('keeps a disc named after nothing but its serial', function () {
    // Nothing follows the serial, so there is no prefix to take off — the
    // serial IS the name, and it stays.
    expect((new PS2)->titleFor(new OplLayout, 'DVD/SLES_503.86.iso'))->toBe('SLES_503.86');

    // And where a separator does follow, stripping would leave nothing. An
    // empty title slugs to "game" and loses the only thing the file said about
    // itself, so the whole name is kept instead.
    expect((new PS2)->titleFor(new OplLayout, 'DVD/SLES_503.86..iso'))->toBeNull();
});
