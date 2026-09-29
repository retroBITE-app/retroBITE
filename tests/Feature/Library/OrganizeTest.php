<?php

use App\Enums\FileRole;
use App\Enums\GameStatus;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\User;
use App\Services\LibraryScanner;
use App\Support\Console;
use App\Tools\ConsoleTools;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Organize into game folders: a flat PS1 library moved onto the game folders
 * layout, each loose game filed into a folder named for its title with every
 * file of it together, and the rows following the files.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-organize-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/psx');
    config()->set('settings.games_path', $this->root);

    $this->console = new Console('psx');
    ConsoleSourceFolder::add($this->console, null, 'folders');

    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/**
 * A matched PS1 game with these files at these paths under psx/.
 *
 * @param  array<string, FileRole>  $files  path under psx/ => role
 */
function looseGame(string $title, array $files): Game
{
    $game = Game::factory()->forConsole('psx')->create(['title' => $title, 'slug' => Str::slug($title), 'status' => GameStatus::Matched]);
    $parent = null;

    foreach ($files as $relative => $role) {
        File::ensureDirectoryExists(dirname(test()->root.'/psx/'.$relative));
        File::put(test()->root.'/psx/'.$relative, $role === FileRole::Sheet ? 'FILE "'.basename($relative, '.cue').'.bin" BINARY' : 'x');

        $file = GameFile::factory()->for($game)->create([
            'path' => 'psx/'.$relative,
            'filename' => basename($relative),
            'extension' => pathinfo($relative, PATHINFO_EXTENSION),
            'role' => $role,
            'parent_id' => $role === FileRole::Track ? $parent?->id : null,
        ]);

        $parent = $role === FileRole::Sheet ? $file : $parent;
    }

    return $game;
}

function organizeModal(): Testable
{
    return Livewire::test('games.organize-modal', ['console' => 'psx']);
}

it('files a loose cue and its bin into a folder named for the game', function () {
    $game = looseGame('Crash Bandicoot', ['Crash.cue' => FileRole::Sheet, 'Crash.bin' => FileRole::Track]);

    organizeModal()->call('apply')->assertDispatched('library-organized');

    expect(File::exists($this->root.'/psx/Crash Bandicoot/Crash.cue'))->toBeTrue()
        ->and(File::exists($this->root.'/psx/Crash Bandicoot/Crash.bin'))->toBeTrue()
        ->and(File::exists($this->root.'/psx/Crash.cue'))->toBeFalse()
        ->and($game->files()->pluck('path')->sort()->values()->all())
        ->toBe(['psx/Crash Bandicoot/Crash.bin', 'psx/Crash Bandicoot/Crash.cue']);
});

it('moves a playlist with its discs, so it still reads them', function () {
    looseGame('Final Fantasy IX', [
        'Final Fantasy IX.m3u' => FileRole::Playlist,
        'FF9 (Disc 1).cue' => FileRole::Sheet,
        'FF9 (Disc 2).cue' => FileRole::Sheet,
    ]);
    File::put($this->root.'/psx/Final Fantasy IX.m3u', "FF9 (Disc 1).cue\nFF9 (Disc 2).cue\n");

    organizeModal()->call('apply');

    expect(File::files($this->root.'/psx/Final Fantasy IX'))->toHaveCount(3)
        ->and(File::get($this->root.'/psx/Final Fantasy IX/Final Fantasy IX.m3u'))->toContain('FF9 (Disc 2).cue');
});

it('previews the games it will move and the ones it leaves', function () {
    looseGame('Crash Bandicoot', ['Crash.cue' => FileRole::Sheet, 'Crash.bin' => FileRole::Track]);
    looseGame('Spyro the Dragon', ['Spyro the Dragon/Spyro.cue' => FileRole::Sheet]);

    $plan = organizeModal()->instance()->plan;

    expect($plan['moves'])->toBe([['title' => 'Crash Bandicoot', 'folder' => 'Crash Bandicoot', 'files' => 2]])
        ->and($plan['filed'])->toBe(1);
});

it('names the folder for the title, without what a card will not take', function () {
    $game = Game::factory()->forConsole('psx')->make(['title' => 'Resident Evil: Director\'s Cut / Dual Shock?.']);

    expect(ConsoleTools::for($this->console)->folderFor($game))->toBe('Resident Evil Director\'s Cut Dual Shock');
});

it('leaves a game whose folder is already taken, and moves the rest', function () {
    looseGame('Crash Bandicoot', ['Crash.cue' => FileRole::Sheet]);
    looseGame('Tekken 3', ['Tekken 3.cue' => FileRole::Sheet]);

    // Somebody's own file where the first one's would go.
    File::ensureDirectoryExists($this->root.'/psx/Crash Bandicoot');
    File::put($this->root.'/psx/Crash Bandicoot/Crash.cue', 'another copy');

    organizeModal()->call('apply');

    expect(File::get($this->root.'/psx/Crash Bandicoot/Crash.cue'))->toBe('another copy')
        ->and(File::exists($this->root.'/psx/Crash.cue'))->toBeTrue()
        ->and(File::exists($this->root.'/psx/Tekken 3/Tekken 3.cue'))->toBeTrue();
});

it('keeps the same games through a rescan afterwards', function () {
    $game = looseGame('Crash Bandicoot', ['Crash.cue' => FileRole::Sheet, 'Crash.bin' => FileRole::Track]);

    organizeModal()->call('apply');

    app(LibraryScanner::class)->scan($this->console);

    // Named for its folder now, which is the game's own title.
    expect(Game::query()->count())->toBe(1)
        ->and(GameFile::query()->pluck('game_id')->unique()->all())->toBe([$game->id])
        ->and(GameFile::query()->whereNotNull('missing_since')->count())->toBe(0);
});

it('offers organizing only on the game folders layout', function () {
    looseGame('Crash Bandicoot', ['Crash.cue' => FileRole::Sheet]);

    expect(Livewire::test('games.index', ['console' => 'psx'])->instance()->canOrganize)->toBeTrue();

    ConsoleSourceFolder::setLayout($this->console, 'custom');

    expect(Livewire::test('games.index', ['console' => 'psx'])->instance()->canOrganize)->toBeFalse();

    expect(organizeModal()->instance()->plan['moves'])->toBe([]);
});
