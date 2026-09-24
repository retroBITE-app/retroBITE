<?php

use App\Enums\FileRole;
use App\Enums\LibraryFileRejection;
use App\Exceptions\LibraryFileRejected;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Models\User;
use App\Services\LibraryFiles;
use App\Support\Console;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The game page's two writes into somebody's library: moving a game between
 * its layout's folders, and deleting one of its files. Both go through the
 * gate, and neither is allowed to cost the game its details or its artwork.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-files-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/ps2');
    File::ensureDirectoryExists($this->root.'/snes');
    config()->set('settings.games_path', $this->root);

    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function library(): LibraryFiles
{
    return app(LibraryFiles::class);
}

/**
 * A game with these files on disk and in the table, paths relative to the library.
 *
 * @param  array<int, string>  $paths
 */
function gameWithFiles(string $console, array $paths): Game
{
    $game = Game::factory()->forConsole($console)->create();

    foreach ($paths as $path) {
        File::ensureDirectoryExists(dirname(test()->root.'/'.$path));
        File::put(test()->root.'/'.$path, 'bytes of '.basename($path));

        GameFile::factory()->for($game)->create([
            'path' => $path,
            'filename' => basename($path),
            'extension' => pathinfo($path, PATHINFO_EXTENSION),
            'role' => Str::endsWith($path, '.cue') ? FileRole::Sheet : FileRole::Rom,
        ]);
    }

    return $game->fresh();
}

function rejection(callable $attempt): ?LibraryFileRejection
{
    try {
        $attempt();
    } catch (LibraryFileRejected $e) {
        return $e->reason;
    }

    return null;
}

describe('move', function () {
    beforeEach(function () {
        ConsoleSourceFolder::add(new Console('ps2'), null, 'opl');
    });

    it('offers the other OPL folder', function () {
        $game = gameWithFiles('ps2', ['ps2/DVD/Game.iso']);

        expect(library()->currentDestination($game))->toBe('DVD')
            ->and(library()->moveTargets($game))->toBe(['CD' => 'ps2/CD/']);
    });

    it('moves every file of the game and records where they went', function () {
        $game = gameWithFiles('ps2', ['ps2/DVD/Game.cue', 'ps2/DVD/Game (Track 1).bin', 'ps2/DVD/Game (Track 2).bin']);

        library()->moveGame($game, 'CD');

        foreach (['Game.cue', 'Game (Track 1).bin', 'Game (Track 2).bin'] as $name) {
            expect(File::exists($this->root.'/ps2/CD/'.$name))->toBeTrue()
                ->and(File::exists($this->root.'/ps2/DVD/'.$name))->toBeFalse();
        }

        expect($game->files()->pluck('path')->sort()->values()->all())
            ->toBe(['ps2/CD/Game (Track 1).bin', 'ps2/CD/Game (Track 2).bin', 'ps2/CD/Game.cue']);
    });

    it('refuses a folder the game is already in', function () {
        $game = gameWithFiles('ps2', ['ps2/DVD/Game.iso']);

        expect(rejection(function () use ($game): void {
            library()->moveGame($game, 'DVD');
        }))->toBe(LibraryFileRejection::SameFolder);
    });

    it('refuses a folder the layout does not read', function () {
        $game = gameWithFiles('ps2', ['ps2/DVD/Game.iso']);

        expect(rejection(function () use ($game): void {
            library()->moveGame($game, 'ART');
        }))->toBe(LibraryFileRejection::Destination);

        expect(File::exists($this->root.'/ps2/DVD/Game.iso'))->toBeTrue();
    });

    it('refuses a game with a file missing from disk', function () {
        $game = gameWithFiles('ps2', ['ps2/DVD/Game.cue', 'ps2/DVD/Game.bin']);
        File::delete($this->root.'/ps2/DVD/Game.bin');

        expect(rejection(function () use ($game): void {
            library()->moveGame($game, 'CD');
        }))->toBe(LibraryFileRejection::Missing);

        expect(File::exists($this->root.'/ps2/DVD/Game.cue'))->toBeTrue();
    });

    it('moves nothing when one name is already taken at the target', function () {
        $game = gameWithFiles('ps2', ['ps2/DVD/Game.cue', 'ps2/DVD/Game.bin']);
        File::ensureDirectoryExists($this->root.'/ps2/CD');
        File::put($this->root.'/ps2/CD/Game.bin', 'somebody else');

        expect(rejection(function () use ($game): void {
            library()->moveGame($game, 'CD');
        }))->toBe(LibraryFileRejection::Exists);

        expect(File::exists($this->root.'/ps2/DVD/Game.cue'))->toBeTrue()
            ->and(File::exists($this->root.'/ps2/CD/Game.cue'))->toBeFalse()
            ->and(File::get($this->root.'/ps2/CD/Game.bin'))->toBe('somebody else');
    });

    it('refuses to move through a symlinked folder', function () {
        $game = gameWithFiles('ps2', ['ps2/DVD/Game.iso']);

        $elsewhere = $this->root.'-elsewhere';
        File::ensureDirectoryExists($elsewhere);
        symlink($elsewhere, $this->root.'/ps2/CD');

        try {
            expect(rejection(function () use ($game): void {
                library()->moveGame($game, 'CD');
            }))->toBe(LibraryFileRejection::Unwritable);

            expect(File::exists($elsewhere.'/Game.iso'))->toBeFalse()
                ->and(File::exists($this->root.'/ps2/DVD/Game.iso'))->toBeTrue();
        } finally {
            File::deleteDirectory($elsewhere);
        }
    });

    it('offers nowhere to a custom-layout game already at the root', function () {
        ConsoleSourceFolder::add(new Console('snes'));
        $game = gameWithFiles('snes', ['snes/Mario.sfc']);

        expect(library()->moveTargets($game))->toBe([]);
    });
});

describe('delete', function () {
    beforeEach(function () {
        ConsoleSourceFolder::add(new Console('snes'));
    });

    it('deletes the file and its row, and nothing about the game', function () {
        Storage::fake('media');

        $game = gameWithFiles('snes', ['snes/Mario.sfc']);
        $game->update(['title' => 'Super Mario World']);
        $media = Media::factory()->for($game)->create();
        Storage::disk('media')->put($media->path, 'art');

        library()->deleteFile($game, $game->files->first()->id);

        expect(File::exists($this->root.'/snes/Mario.sfc'))->toBeFalse()
            ->and(GameFile::query()->count())->toBe(0)
            ->and($game->fresh()?->title)->toBe('Super Mario World')
            ->and(Media::query()->whereKey($media->id)->exists())->toBeTrue();

        Storage::disk('media')->assertExists($media->path);
    });

    it('only forgets a file that is already gone from disk', function () {
        $game = gameWithFiles('snes', ['snes/Mario.sfc']);
        File::delete($this->root.'/snes/Mario.sfc');

        library()->deleteFile($game, $game->files->first()->id);

        expect(GameFile::query()->count())->toBe(0)
            ->and(Game::query()->whereKey($game->id)->exists())->toBeTrue();
    });

    it('refuses a file that belongs to another game', function () {
        $game = gameWithFiles('snes', ['snes/Mario.sfc']);
        $other = gameWithFiles('snes', ['snes/Zelda.sfc']);

        expect(rejection(function () use ($game, $other): void {
            library()->deleteFile($game, $other->files->first()->id);
        }))->toBe(LibraryFileRejection::NotThisGame);

        expect(File::exists($this->root.'/snes/Zelda.sfc'))->toBeTrue();
    });

    it('refuses to delete through a symlink', function () {
        $outside = $this->root.'-outside.sfc';
        File::put($outside, 'not the library');

        $game = Game::factory()->forConsole('snes')->create();
        symlink($outside, $this->root.'/snes/Mario.sfc');
        $file = GameFile::factory()->for($game)->create(['path' => 'snes/Mario.sfc', 'filename' => 'Mario.sfc']);

        try {
            expect(rejection(function () use ($game, $file): void {
                library()->deleteFile($game, $file->id);
            }))->toBe(LibraryFileRejection::Unwritable);

            expect(File::exists($outside))->toBeTrue()
                ->and(GameFile::query()->whereKey($file->id)->exists())->toBeTrue();
        } finally {
            File::delete($outside);
        }
    });
});

describe('the game page', function () {
    it('offers the move in the actions menu and no longer offers deleting the game from it', function () {
        ConsoleSourceFolder::add(new Console('ps2'), null, 'opl');
        $game = gameWithFiles('ps2', ['ps2/DVD/Game.iso']);

        $this->get(route('games.show', $game->routeParameters()))
            ->assertOk()
            ->assertSee('ps2/CD/')
            ->assertDontSee('Not available yet.');
    });

    it('explains why a game at its only folder cannot move', function () {
        ConsoleSourceFolder::add(new Console('snes'));
        $game = gameWithFiles('snes', ['snes/Mario.sfc']);

        Livewire::test('games.show', ['game' => $game])
            ->assertSee('Already in the only folder this console&#039;s layout reads.', false);
    });

    it('moves the game from the page', function () {
        ConsoleSourceFolder::add(new Console('ps2'), null, 'opl');
        $game = gameWithFiles('ps2', ['ps2/DVD/Game.iso']);

        Livewire::test('games.show', ['game' => $game])
            ->call('moveTo', 'CD')
            ->assertSee('ps2/DVD/');

        expect($game->files()->value('path'))->toBe('ps2/CD/Game.iso');
    });

    it('deletes a file from the files table', function () {
        ConsoleSourceFolder::add(new Console('snes'));
        $game = gameWithFiles('snes', ['snes/Mario.sfc', 'snes/Mario (Rev 1).sfc']);
        $file = $game->files->firstWhere('filename', 'Mario.sfc');

        Livewire::test('games.show', ['game' => $game, 'tab' => 'files'])
            ->set('tab', 'files')
            ->assertSee('Delete Mario.sfc')
            ->call('deleteFile', $file->id)
            ->assertDontSee('Delete Mario.sfc')
            ->assertSee('Delete Mario (Rev 1).sfc');

        expect(File::exists($this->root.'/snes/Mario.sfc'))->toBeFalse()
            ->and(File::exists($this->root.'/snes/Mario (Rev 1).sfc'))->toBeTrue();
    });
});
