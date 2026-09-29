<?php

use App\Enums\FileRole;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Services\LibraryScanner;
use App\Support\Console;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * A layout decides what is even a candidate before anything is read as a game.
 *
 * The case that made this necessary: an Open PS2 Loader drive keeps ART/, CFG/,
 * VMC/ and THM/ beside the games, and scanning them turns a library of eleven
 * titles into a hundred. The other half is the filename prefix OPL puts there,
 * which has to come off the title without the layout knowing what a PS2 serial
 * is — see Ps2ToolboxTest for that seam from the other side.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-layout-'.Str::random(8);
    File::ensureDirectoryExists($this->root);
    config()->set('settings.games_path', $this->root);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function oplTree(): void
{
    $files = [
        'ps2/DVD/SLES_503.30.Grand Theft Auto III (Europe).iso',
        'ps2/CD/SLES_503.86.Crash Bandicoot The Wrath of Cortex.iso',
        'ps2/ART/SLES_503.86_COV.png',
        'ps2/CFG/SLES_503.86.cfg',
        'ps2/VMC/Crash.bin',
        'ps2/THM/theme.bin',
        'ps2/APPS/OPL.ELF',
    ];

    foreach ($files as $relative) {
        $path = test()->root.'/'.$relative;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'x');
    }
}

function scanPs2(?string $layout): void
{
    $console = new Console('ps2');
    ConsoleSourceFolder::add($console, null, $layout);

    app(LibraryScanner::class)->scan($console);
}

it('reads only the game directories under the opl layout', function () {
    oplTree();

    scanPs2('opl');

    expect(GameFile::pluck('path')->sort()->values()->all())->toBe([
        'ps2/CD/SLES_503.86.Crash Bandicoot The Wrath of Cortex.iso',
        'ps2/DVD/SLES_503.30.Grand Theft Auto III (Europe).iso',
    ]);

    // And the serial prefix comes off the title — only here: the custom layout
    // below keeps it.
    expect(Game::pluck('title')->sort()->values()->all())->toBe([
        'Crash Bandicoot The Wrath of Cortex',
        'Grand Theft Auto III (Europe)',
    ]);
});

it('reads the same tree whole under the custom layout', function () {
    oplTree();

    scanPs2('custom');

    // .png, .cfg and .ELF are not PS2 game extensions, so they are skipped as
    // unplayable rather than filed — but the two .bin files are, and under a
    // layout that ignores nothing they become games of their own. That is the
    // difference the layout makes, stated as a count.
    expect(GameFile::count())->toBe(4)
        ->and(Game::pluck('title')->contains('Crash'))->toBeTrue()
        ->and(Game::pluck('title')->contains('SLES_503.86.Crash Bandicoot The Wrath of Cortex'))->toBeTrue();
});

it('assumes the console default when nobody was asked', function () {
    oplTree();

    scanPs2(null);

    // ps2 declares 'custom' as its default, so an install that never saw the
    // layout step scans exactly as it did before layouts existed.
    expect(ConsoleSourceFolder::layoutKeyFor(new Console('ps2')))->toBe('custom')
        ->and(GameFile::count())->toBe(4);
});

it('falls back rather than throwing on a layout config no longer ships', function () {
    oplTree();

    $console = new Console('ps2');
    ConsoleSourceFolder::add($console, null, 'nonesuch');

    app(LibraryScanner::class)->scan($console);

    expect(ConsoleSourceFolder::layoutKeyFor($console))->toBe('custom')
        ->and(GameFile::count())->toBe(4);
});

it('does not descend below a game directory under opl', function () {
    // OPL launches nothing from a subdirectory, so neither does the scan.
    $path = test()->root.'/ps2/DVD/backup/SLES_503.30.Game.iso';
    File::ensureDirectoryExists(dirname($path));
    File::put($path, 'x');
    File::put(test()->root.'/ps2/DVD/SLES_503.86.Game.iso', 'x');

    scanPs2('opl');

    expect(GameFile::pluck('path')->all())->toBe(['ps2/DVD/SLES_503.86.Game.iso']);
});

/** A PS1 card laid out one folder per game. */
function foldersTree(): void
{
    $files = [
        'psx/Crash Bandicoot/Crash.cue' => "FILE \"Crash.bin\" BINARY\n  TRACK 01 MODE2/2352\n",
        'psx/Crash Bandicoot/Crash.bin' => 'x',
        'psx/Final Fantasy IX/FF9 (Disc 1).cue' => "FILE \"FF9 (Disc 1).bin\" BINARY\n  TRACK 01 MODE2/2352\n",
        'psx/Final Fantasy IX/FF9 (Disc 1).bin' => 'x',
        'psx/Final Fantasy IX/FF9 (Disc 2).cue' => "FILE \"FF9 (Disc 2).bin\" BINARY\n  TRACK 01 MODE2/2352\n",
        'psx/Final Fantasy IX/FF9 (Disc 2).bin' => 'x',
    ];

    foreach ($files as $relative => $contents) {
        File::ensureDirectoryExists(dirname(test()->root.'/'.$relative));
        File::put(test()->root.'/'.$relative, $contents);
    }
}

it('makes one game per folder, named for the folder, under game folders', function () {
    foldersTree();

    ConsoleSourceFolder::add(new Console('psx'), null, 'folders');
    app(LibraryScanner::class)->scan(new Console('psx'));

    // Both discs of a set land on one game with no playlist to say so.
    expect(Game::query()->orderBy('title')->pluck('title')->all())->toBe(['Crash Bandicoot', 'Final Fantasy IX'])
        ->and(Game::query()->where('title', 'Final Fantasy IX')->sole()->files()->count())->toBe(4);
});

it('numbers the discs once the folder carries a playlist', function () {
    foldersTree();
    File::put($this->root.'/psx/Final Fantasy IX/Final Fantasy IX.m3u', "FF9 (Disc 1).cue\nFF9 (Disc 2).cue\n");

    ConsoleSourceFolder::add(new Console('psx'), null, 'folders');
    app(LibraryScanner::class)->scan(new Console('psx'));

    $game = Game::query()->where('title', 'Final Fantasy IX')->sole();

    expect($game->files()->where('role', FileRole::Sheet)->orderBy('disc_number')->pluck('disc_number')->all())->toBe([1, 2]);
});
