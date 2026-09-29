<?php

use App\Enums\FileRole;
use App\Jobs\MeasureLibrary;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\User;
use App\Support\Console;
use App\Support\LibraryStorage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * What the library takes up, and the three places that report it.
 *
 * The figure is the identified games, summed from the database — the same
 * "games" every other count on the site means — against what is free on the
 * disk. No page reads the disk: the free space is taken by MeasureLibrary in
 * the background, and until it has been, the figure is unknown rather than
 * zero.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-storage-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/ps2/DVD');
    config()->set('settings.games_path', $this->root);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/** One game with one file of the given size, identified or not. */
function gameOfSize(int $bytes, bool $identified = true, bool $missing = false): Game
{
    $factory = Game::factory()->forConsole('ps2');
    $game = ($identified ? $factory->matched() : $factory)->create();

    GameFile::factory()->for($game)->create([
        'path' => 'ps2/DVD/'.Str::random(6).'.iso',
        'extension' => 'iso',
        'role' => FileRole::Rom,
        'size_bytes' => $bytes,
        'missing_since' => $missing ? now() : null,
    ]);

    return $game;
}

it('counts the identified games\' files, and only those', function () {
    gameOfSize(4096);
    gameOfSize(2048);
    // Not a game yet, and a file that is gone: neither takes room it can claim.
    gameOfSize(1000, identified: false);
    gameOfSize(500, missing: true);

    LibraryStorage::measureFree();
    $storage = LibraryStorage::current();

    expect($storage->used)->toBe(6144)
        // The denominator is what the library could grow into, so the bar still
        // fills as the drive does — whatever it was that filled it.
        ->and($storage->free)->toBeGreaterThan(0)
        ->and($storage->total())->toBe($storage->used + $storage->free);
});

it('reads nothing off the disk to answer', function () {
    gameOfSize(1024);
    LibraryStorage::measureFree();

    // Files on the disk that no identified game accounts for change nothing.
    File::put($this->root.'/ps2/DVD/stray.iso', str_repeat('x', 4096));

    expect(LibraryStorage::current()->used)->toBe(1024);
});

it('says nothing until the free space has been measured', function () {
    gameOfSize(1024);

    expect(LibraryStorage::current())->toBeNull();
});

it('says nothing rather than zero when the library folder is not there', function () {
    config()->set('settings.games_path', $this->root.'/not-mounted');

    LibraryStorage::measureFree();

    expect(LibraryStorage::current())->toBeNull();
});

it('takes both readings in the background', function () {
    // The job the schedule and the button run: every console's file count,
    // and the free space, so every page has something to read.
    File::put($this->root.'/ps2/DVD/one.iso', 'x');
    File::put($this->root.'/ps2/DVD/two.iso', 'x');
    ConsoleSourceFolder::create(['console' => 'ps2', 'path' => 'ps2']);

    (new MeasureLibrary)->handle();

    expect(ConsoleSourceFolder::fileCountFor(new Console('ps2')))->toBe(2)
        ->and(LibraryStorage::current())->not->toBeNull();
});

it('has no reading to draw until the free space has been measured, then keeps looking', function () {
    gameOfSize(4096);

    // A bar at zero would say the library is empty, which is the one thing an
    // unmeasured or unreadable disk is not known to be.
    $block = Livewire::test('library-storage')->assertOk()->assertViewHas('storage', null);

    LibraryStorage::measureFree();

    // Measurements arrive as a signal, and the block reads again on it.
    $block->call('$refresh')
        ->assertViewHas('storage', fn (?LibraryStorage $storage): bool => $storage?->used === 4096);
});

it('rides along on every page of the application', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('user.edit'))
        ->assertOk()
        ->assertSeeLivewire('library-storage');
});
