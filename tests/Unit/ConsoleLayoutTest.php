<?php

use App\Support\Layouts\CustomLayout;
use App\Support\Layouts\FoldersLayout;
use App\Support\Layouts\OplLayout;
use App\Support\Layouts\RetroArchLayout;

/**
 * A layout answers one question — is this path a game, or part of the loader's
 * furniture — and it answers it about structure alone.
 *
 * The assertion worth keeping here is the one about what OplLayout does NOT do:
 * it leaves SLES_503.86 in the title. That prefix is a PlayStation 2 serial
 * wearing an OPL convention, so it belongs to the console's toolbox, and a
 * layout that learned to strip it would be carrying one console's identifier
 * format on behalf of all 135.
 */
it('keeps opl to its own two directories', function (string $path, bool $accepted) {
    expect((new OplLayout)->accepts($path))->toBe($accepted);
})->with([
    ['DVD/SLES_503.86.Game.iso', true],
    ['CD/Game.iso', true],
    // Case-folded: the same drive turns up as dvd/ on another machine.
    ['dvd/Game.iso', true],
    ['ART/SLES_503.86_COV.png', false],
    ['CFG/SLES_503.86.cfg', false],
    ['VMC/Game.bin', false],
    ['THM/theme.bin', false],
    ['APPS/OPL.ELF', false],
    // OPL launches nothing from a subdirectory, so neither does the scan.
    ['DVD/backup/Game.iso', false],
    // Nor from the root: a loose file there is not one of its games.
    ['Game.iso', false],
]);

it('leaves the serial prefix alone, because it is not a layout\'s business', function () {
    expect((new OplLayout)->titleFor('DVD/SLES_503.86.Tekken Tag Tournament.iso'))
        ->toBe('SLES_503.86.Tekken Tag Tournament');
});

it('skips retroarch\'s thumbnail cache at any depth', function (string $path, bool $accepted) {
    expect((new RetroArchLayout)->accepts($path))->toBe($accepted);
})->with([
    ['Super Mario World.sfc', true],
    ['Collection/Super Mario World.sfc', true],
    ['media/Named_Boxarts/Super Mario World.png', false],
    ['thumbnails/Super Mario World.png', false],
    ['Named_Snaps/Super Mario World.png', false],
]);

it('accepts everything under the custom layout', function (string $path) {
    expect((new CustomLayout)->accepts($path))->toBeTrue();
})->with([
    ['Game.iso'],
    ['DVD/Game.iso'],
    ['ART/SLES_503.86_COV.png'],
    ['anything/at/any/depth.iso'],
]);

it('takes a filename as it is under the custom layout', function () {
    expect((new CustomLayout)->titleFor('DVD/SLES_503.86.Tekken Tag Tournament.iso'))
        ->toBe('SLES_503.86.Tekken Tag Tournament')
        ->and((new CustomLayout)->titleFor('Castlevania (Europe).iso'))
        ->toBe('Castlevania (Europe)');
});

it('keeps "should exist" and "never a game" as two different lists', function () {
    $opl = new OplLayout;

    // ART is in both: it should be there, and it is not a game. APPS is in the
    // second only — OPL makes it the day somebody runs homebrew, and an empty
    // one explains nothing about how the drive is arranged.
    expect($opl->scaffold())->toContain('ART')
        ->and($opl->ignoredDirectories())->toContain('ART')
        ->and($opl->ignoredDirectories())->toContain('APPS')
        ->and($opl->scaffold())->not->toContain('APPS')
        // And the game directories are in it, which is the point of the whole
        // exercise: choosing OPL should leave somewhere to put a game.
        ->and($opl->scaffold())->toContain('DVD', 'CD');
});

it('makes nothing for the layout that imposes nothing', function () {
    // Custom imposes no structure, so building folders would be imposing some.
    expect((new CustomLayout)->scaffold())->toBe([]);
});

it('lays out retroarch\'s thumbnail tree and nothing else', function () {
    $layout = new RetroArchLayout;

    // The games need nothing made — they sit loose in the console's folder,
    // which is already there. These three are for the artwork.
    expect($layout->scaffold())->toBe([
        'media/Named_Boxarts',
        'media/Named_Snaps',
        'media/Named_Titles',
    ]);

    // And the scanner keeps skipping them, which is the point of the two
    // lists being separate: made on setup, never read as a game.
    expect($layout->accepts('media/Named_Boxarts/Tekken.png'))->toBeFalse();
});

it('names a game after its folder under the game folders layout', function (string $path, string $title) {
    expect((new FoldersLayout)->titleFor($path))->toBe($title);
})->with([
    'a cue in its folder' => ['Crash Bandicoot/Crash.cue', 'Crash Bandicoot'],
    // Two discs, one game: the folder says so, not the file names.
    'a disc of a set' => ['Final Fantasy IX/FF9 (Disc 2).cue', 'Final Fantasy IX'],
    'deeper still' => ['Final Fantasy IX/extras/FF9 (Disc 1).bin', 'Final Fantasy IX'],
    // Not filed yet: the file is the only name there is.
    'loose at the top' => ['Tekken 3.cue', 'Tekken 3'],
]);

it('reads every folder under the game folders layout', function (string $path) {
    expect((new FoldersLayout)->accepts($path))->toBeTrue();
})->with([['Tekken 3.cue'], ['Crash Bandicoot/Crash.bin'], ['Final Fantasy IX/extras/FF9 (Disc 1).bin']]);

it('files games in folders of their own only under the game folders layout', function () {
    expect((new FoldersLayout)->perGameFolders())->toBeTrue()
        ->and((new CustomLayout)->perGameFolders())->toBeFalse()
        ->and((new OplLayout)->perGameFolders())->toBeFalse()
        ->and((new RetroArchLayout)->perGameFolders())->toBeFalse();
});
