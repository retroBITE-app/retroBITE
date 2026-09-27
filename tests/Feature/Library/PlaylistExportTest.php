<?php

use App\Enums\FileRole;
use App\Enums\GameStatus;
use App\Jobs\ScanConsoleFolder;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\User;
use App\Support\Console;
use App\Tools\ConsoleTools;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * "Write playlists": the one file the game folders layout needs written, an
 * .m3u in the folder of every game with more than one disc, so the frontend
 * offers a disc swap and the scanner numbers the discs.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-m3u-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/psx');
    config()->set('settings.games_path', $this->root);

    $this->console = new Console('psx');
    ConsoleSourceFolder::add($this->console, null, 'folders');

    $this->actingAs(User::factory()->create());
    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/**
 * A game in a folder of its own with these files, on disk and in the table.
 *
 * @param  array<int, string>  $files  names inside the folder
 */
function folderGame(string $folder, array $files): Game
{
    $game = Game::factory()->forConsole('psx')->create(['title' => $folder, 'slug' => Str::slug($folder), 'status' => GameStatus::Matched]);

    File::ensureDirectoryExists(test()->root.'/psx/'.$folder);

    foreach ($files as $name) {
        File::put(test()->root.'/psx/'.$folder.'/'.$name, 'x');

        $extension = pathinfo($name, PATHINFO_EXTENSION);

        GameFile::factory()->for($game)->create([
            'path' => 'psx/'.$folder.'/'.$name,
            'filename' => $name,
            'extension' => $extension,
            'role' => match ($extension) {
                'cue' => FileRole::Sheet,
                'm3u' => FileRole::Playlist,
                default => FileRole::Rom,
            },
        ]);
    }

    return $game;
}

/** @return array{written: int, skipped: int, failed: int} */
function writePlaylists(): array
{
    return ConsoleTools::for(test()->console)->export('m3u')->run();
}

it('lists every disc in order, in the game\'s own folder', function () {
    folderGame('Final Fantasy IX', [
        'FF9 (Disc 3).cue', 'FF9 (Disc 3).bin',
        'FF9 (Disc 1).cue', 'FF9 (Disc 1).bin',
        'FF9 (Disc 10).cue', 'FF9 (Disc 10).bin',
        'FF9 (Disc 2).cue', 'FF9 (Disc 2).bin',
    ]);

    expect(writePlaylists())->toBe(['written' => 1, 'skipped' => 0, 'failed' => 0]);

    // By the number the name gives, so ten comes after three, not after one.
    expect(File::get($this->root.'/psx/Final Fantasy IX/Final Fantasy IX.m3u'))
        ->toBe("FF9 (Disc 1).cue\nFF9 (Disc 2).cue\nFF9 (Disc 3).cue\nFF9 (Disc 10).cue\n");
});

it('lists the images themselves where a folder has no sheets', function () {
    folderGame('Metal Gear Solid', ['MGS (Disc 2).chd', 'MGS (Disc 1).chd']);

    writePlaylists();

    expect(File::get($this->root.'/psx/Metal Gear Solid/Metal Gear Solid.m3u'))->toBe("MGS (Disc 1).chd\nMGS (Disc 2).chd\n");
});

it('leaves single discs and games that have a playlist alone', function () {
    folderGame('Crash Bandicoot', ['Crash.cue', 'Crash.bin']);
    folderGame('Parasite Eve', ['PE (Disc 1).cue', 'PE (Disc 2).cue', 'Parasite Eve.m3u']);

    expect(writePlaylists())->toBe(['written' => 0, 'skipped' => 2, 'failed' => 0])
        ->and(File::exists($this->root.'/psx/Crash Bandicoot/Crash Bandicoot.m3u'))->toBeFalse();
});

it('never writes over a playlist already on disk', function () {
    folderGame('Final Fantasy VIII', ['FF8 (Disc 1).cue', 'FF8 (Disc 2).cue']);
    File::put($this->root.'/psx/Final Fantasy VIII/Final Fantasy VIII.m3u', "somebody's own\n");

    writePlaylists();

    expect(File::get($this->root.'/psx/Final Fantasy VIII/Final Fantasy VIII.m3u'))->toBe("somebody's own\n");
});

it('scans afterwards so the playlist links the discs', function () {
    folderGame('Final Fantasy IX', ['FF9 (Disc 1).cue', 'FF9 (Disc 2).cue']);

    writePlaylists();

    Queue::assertPushed(ScanConsoleFolder::class);
});

it('writes nothing on a console laid out some other way', function () {
    folderGame('Final Fantasy IX', ['FF9 (Disc 1).cue', 'FF9 (Disc 2).cue']);
    ConsoleSourceFolder::setLayout($this->console, 'custom');

    expect(ConsoleTools::for($this->console)->canExport())->toBeFalse()
        ->and(writePlaylists())->toBe(['written' => 0, 'skipped' => 0, 'failed' => 0])
        ->and(File::exists($this->root.'/psx/Final Fantasy IX/Final Fantasy IX.m3u'))->toBeFalse();
});

it('names each console\'s exports in its own words on the shelf', function () {
    folderGame('Final Fantasy IX', ['FF9 (Disc 1).cue', 'FF9 (Disc 2).cue']);

    Livewire::test('games.index', ['console' => 'psx'])
        ->assertSee('Write playlists')
        ->assertDontSee('Write OPL');

    File::ensureDirectoryExists($this->root.'/ps2');
    ConsoleSourceFolder::add(new Console('ps2'), null, 'opl');
    Game::factory()->forConsole('ps2')->matched()->create();

    Livewire::test('games.index', ['console' => 'ps2'])
        ->assertSee('Write OPL configs')
        ->assertSee('Write OPL art')
        ->assertDontSee('Write playlists');
});
