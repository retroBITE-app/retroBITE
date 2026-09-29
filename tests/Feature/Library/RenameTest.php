<?php

use App\Enums\FileRole;
use App\Enums\GameStatus;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\User;
use App\Services\LibraryFiles;
use App\Services\LibraryScanner;
use App\Support\Console;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Adding the license ID to a PS2 game's file name, or taking it off: OPL's
 * old "SLES_503.86.Title.iso" form. The files move through the gate and the
 * rows follow them, so a rescan finds the same games under their new names.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-rename-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/ps2/DVD');
    config()->set('settings.games_path', $this->root);

    ConsoleSourceFolder::add(new Console('ps2'), null, 'opl');

    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/** A matched PS2 game with one disc image on disk, its license ID already read. */
function renamableGame(string $filename, ?string $licenseId = 'SLES_503.86', string $title = 'Tekken Tag Tournament'): Game
{
    // The slug the scanner itself gives a file of that title: a rescan finds
    // a file's game by it, so a made-up one would split even an unrenamed file.
    $game = Game::factory()->forConsole('ps2')->create(['title' => $title, 'slug' => Str::slug($title), 'status' => GameStatus::Matched]);
    $path = 'ps2/DVD/'.$filename;

    File::put(test()->root.'/'.$path, 'disc image');

    GameFile::factory()->for($game)->create([
        'path' => $path,
        'filename' => $filename,
        'extension' => 'iso',
        'role' => FileRole::Rom,
        'license_id' => $licenseId,
        'md5' => md5('disc image'),
    ]);

    return $game;
}

function renameModal(?int $gameId = null): Testable
{
    return Livewire::test('games.rename-modal', ['console' => 'ps2', 'gameId' => $gameId]);
}

it('adds the license ID on disk and in the table', function () {
    $game = renamableGame('Tekken Tag Tournament.iso');

    renameModal()->set('mode', 'add')->call('apply')->assertDispatched('library-renamed');

    $file = $game->files()->sole();

    expect(File::exists($this->root.'/ps2/DVD/SLES_503.86.Tekken Tag Tournament.iso'))->toBeTrue()
        ->and(File::exists($this->root.'/ps2/DVD/Tekken Tag Tournament.iso'))->toBeFalse()
        ->and($file->path)->toBe('ps2/DVD/SLES_503.86.Tekken Tag Tournament.iso')
        ->and($file->filename)->toBe('SLES_503.86.Tekken Tag Tournament.iso');
});

it('takes the license ID off again, back to the name it started with', function () {
    renamableGame('Tekken Tag Tournament.iso');

    renameModal()->set('mode', 'add')->call('apply');
    renameModal()->set('mode', 'remove')->call('apply');

    expect(File::exists($this->root.'/ps2/DVD/Tekken Tag Tournament.iso'))->toBeTrue()
        ->and(GameFile::query()->sole()->filename)->toBe('Tekken Tag Tournament.iso');
});

it('previews what it will do and why the rest are left', function () {
    renamableGame('Tekken Tag Tournament.iso');
    renamableGame('SLES_524.67.Jak II.iso', 'SLES_524.67', 'Jak II');
    renamableGame('Unread.iso', null, 'Unread');

    $plan = renameModal()->set('mode', 'add')->instance()->plan;

    expect($plan['renames'])->toBe([['from' => 'Tekken Tag Tournament.iso', 'to' => 'SLES_503.86.Tekken Tag Tournament.iso']])
        ->and($plan['unchanged'])->toBe(1)
        ->and($plan['unread'])->toBe(1)
        ->and($plan['sets'])->toBe(0);
});

it('leaves a cue/bin set alone, since its sheet names the files', function () {
    $game = Game::factory()->forConsole('ps2')->create(['status' => GameStatus::Matched]);
    File::put($this->root.'/ps2/DVD/Game.cue', 'FILE "Game.bin" BINARY');
    File::put($this->root.'/ps2/DVD/Game.bin', 'track');
    $sheet = GameFile::factory()->for($game)->create(['path' => 'ps2/DVD/Game.cue', 'filename' => 'Game.cue', 'extension' => 'cue', 'role' => FileRole::Sheet]);
    GameFile::factory()->for($game)->create(['path' => 'ps2/DVD/Game.bin', 'filename' => 'Game.bin', 'extension' => 'bin', 'role' => FileRole::Track, 'parent_id' => $sheet->id, 'license_id' => 'SLES_503.86']);

    $plan = renameModal()->set('mode', 'add')->instance()->plan;

    expect($plan['renames'])->toBe([])
        ->and($plan['sets'])->toBe(1);
});

it('skips a name already taken and renames the rest', function () {
    renamableGame('Tekken Tag Tournament.iso');
    renamableGame('Jak II.iso', 'SLES_524.67', 'Jak II');

    // Somebody's own copy already holds the name the first would take.
    File::put($this->root.'/ps2/DVD/SLES_503.86.Tekken Tag Tournament.iso', 'another copy');

    $game = GameFile::query()->where('filename', 'Tekken Tag Tournament.iso')->sole();
    $jak = GameFile::query()->where('filename', 'Jak II.iso')->sole();

    $renamed = app(LibraryFiles::class)->renameFiles(new Console('ps2'), [
        ['file' => $game, 'to' => 'SLES_503.86.Tekken Tag Tournament.iso'],
        ['file' => $jak, 'to' => 'SLES_524.67.Jak II.iso'],
    ]);

    expect($renamed)->toBe(1)
        ->and(File::get($this->root.'/ps2/DVD/SLES_503.86.Tekken Tag Tournament.iso'))->toBe('another copy')
        ->and($game->refresh()->filename)->toBe('Tekken Tag Tournament.iso')
        ->and($jak->refresh()->filename)->toBe('SLES_524.67.Jak II.iso');
});

it('refuses a new name that would move the file out of its folder', function () {
    renamableGame('Tekken Tag Tournament.iso');
    $file = GameFile::query()->sole();

    $renamed = app(LibraryFiles::class)->renameFiles(new Console('ps2'), [
        ['file' => $file, 'to' => '../Tekken.iso'],
    ]);

    expect($renamed)->toBe(0)
        ->and(File::exists($this->root.'/ps2/DVD/Tekken Tag Tournament.iso'))->toBeTrue();
});

it('keeps the same game and its checksum through a rescan', function () {
    $game = renamableGame('Tekken Tag Tournament.iso');

    renameModal()->set('mode', 'add')->call('apply');

    app(LibraryScanner::class)->scan(new Console('ps2'));

    $files = GameFile::query()->get();

    expect(Game::query()->count())->toBe(1)
        ->and($files)->toHaveCount(1)
        ->and($files->first()->game_id)->toBe($game->id)
        ->and($files->first()->md5)->toBe(md5('disc image'))
        ->and($files->first()->missing_since)->toBeNull();
});

it('offers Rename files on the shelf only for a PS2 drive arranged for OPL', function () {
    renamableGame('Tekken Tag Tournament.iso');

    expect(Livewire::test('games.index', ['console' => 'ps2'])->instance()->canRename)->toBeTrue();

    ConsoleSourceFolder::setLayout(new Console('ps2'), 'custom');

    expect(Livewire::test('games.index', ['console' => 'ps2'])->instance()->canRename)->toBeFalse();
});

it('renames one game from its own page, starting on the step that applies', function () {
    $game = renamableGame('SLES_503.86.Tekken Tag Tournament.iso');
    renamableGame('SLES_524.67.Jak II.iso', 'SLES_524.67', 'Jak II');

    expect(Livewire::test('games.show', ['game' => $game])->instance()->canRename)->toBeTrue();

    renameModal($game->id)
        ->assertSet('mode', 'remove')
        ->call('apply');

    expect($game->files()->sole()->filename)->toBe('Tekken Tag Tournament.iso')
        // The other game on the shelf is not this page's to rename.
        ->and(File::exists($this->root.'/ps2/DVD/SLES_524.67.Jak II.iso'))->toBeTrue();
});
