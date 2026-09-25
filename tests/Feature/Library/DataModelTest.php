<?php

use App\Enums\FileRole;
use App\Enums\GameStatus;
use App\Enums\MediaKind;
use App\Models\AppSetting;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameCollection;
use App\Models\GameFile;
use App\Models\Media;
use App\Support\Console;
use App\Support\MediaTypes;
use Illuminate\Database\QueryException;

it('creates a game as an unidentified placeholder', function () {
    $game = Game::factory()->create();

    expect($game->status)->toBe(GameStatus::Placeholder)
        ->and($game->screenscraper_id)->toBeNull()
        ->and($game->status->awaitingLookup())->toBeTrue();
});

it('refuses two games with the same provider id', function () {
    Game::factory()->matched(19256)->create();

    Game::factory()->matched(19256)->create();
})->throws(QueryException::class);

it('lets two consoles hold a game with the same slug', function () {
    Game::factory()->forConsole('snes')->create(['title' => 'Aladdin', 'slug' => 'aladdin']);
    $second = Game::factory()->forConsole('megadrive')->create(['title' => 'Aladdin', 'slug' => 'aladdin']);

    expect($second->exists)->toBeTrue()
        ->and(Game::where('slug', 'aladdin')->count())->toBe(2);
});

it('refuses the same slug twice on one console', function () {
    Game::factory()->forConsole('snes')->create(['slug' => 'aladdin']);

    Game::factory()->forConsole('snes')->create(['slug' => 'aladdin']);
})->throws(QueryException::class);

it('refuses two files at the same path', function () {
    GameFile::factory()->create(['path' => 'snes/mario.sfc']);

    GameFile::factory()->create(['path' => 'snes/mario.sfc']);
})->throws(QueryException::class);

it('allows two files with identical content at different paths', function () {
    // The same dump copied into a backup folder is not an error, and a unique
    // index on the checksum would fail the scan halfway through.
    $game = Game::factory()->create();

    GameFile::factory()->hashed()->for($game)->create(['path' => 'snes/mario.sfc', 'md5' => 'd41d8cd98f00b204e9800998ecf8427e']);
    $copy = GameFile::factory()->hashed()->for($game)->create(['path' => 'snes/backup/mario.sfc', 'md5' => 'd41d8cd98f00b204e9800998ecf8427e']);

    expect($copy->exists)->toBeTrue();
});

it('deletes a game and its files and media together', function () {
    $game = Game::factory()->matched()->create();
    GameFile::factory()->count(3)->for($game)->create();
    Media::factory()->count(2)->for($game)->create();

    $game->delete();

    expect(GameFile::count())->toBe(0)
        ->and(Media::count())->toBe(0);
});

it('models a multi-disc game as one game with a playlist over its discs', function () {
    $game = Game::factory()->matched()->forConsole('psx')->create();

    $playlist = GameFile::factory()->for($game)->role(FileRole::Playlist)->create([
        'path' => 'psx/ff9/Final Fantasy IX.m3u', 'filename' => 'Final Fantasy IX.m3u', 'extension' => 'm3u',
    ]);

    foreach (range(1, 4) as $disc) {
        GameFile::factory()->for($game)->disc($disc)->create([
            'path' => "psx/ff9/disc{$disc}.bin",
            'parent_id' => $playlist->id,
        ]);
    }

    expect($game->files()->count())->toBe(5)
        ->and($playlist->children)->toHaveCount(4)
        ->and($playlist->children->pluck('disc_number')->all())->toBe([1, 2, 3, 4])
        ->and($playlist->children->first()->parent->is($playlist))->toBeTrue();
});

it('picks only an identifiable file to look up, lowest disc first', function () {
    $game = Game::factory()->forConsole('psx')->create();

    // Tracks and playlists are not worth a request: the provider's entries for
    // them are unreliable and a miss costs the scarce failed-lookup quota.
    GameFile::factory()->for($game)->role(FileRole::Playlist)->create(['path' => 'psx/a.m3u']);
    GameFile::factory()->for($game)->disc(2)->create(['path' => 'psx/d2.bin']);
    $disc1 = GameFile::factory()->for($game)->role(FileRole::Rom)->create(['path' => 'psx/d1.iso', 'disc_number' => 1]);
    GameFile::factory()->for($game)->role(FileRole::Rom)->create(['path' => 'psx/d3.iso', 'disc_number' => 3]);

    expect($game->identifiableFile()->is($disc1))->toBeTrue();
});

it('skips a missing file when choosing what to look up', function () {
    $game = Game::factory()->create();
    GameFile::factory()->for($game)->missing()->create(['path' => 'snes/gone.sfc', 'disc_number' => 1]);
    $present = GameFile::factory()->for($game)->create(['path' => 'snes/here.sfc', 'disc_number' => 2]);

    expect($game->identifiableFile()->is($present))->toBeTrue();
});

it('treats a missing file as soft-deleted rather than gone', function () {
    $game = Game::factory()->create();
    GameFile::factory()->for($game)->create(['path' => 'snes/a.sfc']);
    GameFile::factory()->for($game)->missing()->create(['path' => 'snes/b.sfc']);

    expect(GameFile::present()->count())->toBe(1)
        ->and(GameFile::missing()->count())->toBe(1)
        // The row survives, so identification is not thrown away with it.
        ->and(GameFile::count())->toBe(2);
});

it('refuses the same image twice for one game but allows it across games', function () {
    $md5 = 'ca32e3bfb4507f9713f37b5d345baab1';
    $one = Game::factory()->matched()->create();
    $two = Game::factory()->matched()->create();

    Media::factory()->for($one)->create(['md5' => $md5]);
    Media::factory()->for($two)->create(['md5' => $md5]);

    expect(Media::count())->toBe(2);

    Media::factory()->for($one)->create(['md5' => $md5]);
})->throws(QueryException::class);

it('allows several screenshots of different content on one game', function () {
    $game = Game::factory()->matched()->create();

    Media::factory()->count(4)->for($game)->ofType('ss')->create();

    expect($game->media()->where('screenscraper_type', 'ss')->count())->toBe(4);
});

it('finds cover art by display role, preferring box-2D over box-3D', function () {
    $game = Game::factory()->matched()->create();
    Media::factory()->for($game)->ofType('box-3D', 'us')->create();
    Media::factory()->for($game)->ofType('box-2D', 'us')->create();
    Media::factory()->for($game)->ofType('fanart')->create();

    $cover = $game->media()->ofKind(MediaKind::Cover)->first();

    expect($cover->screenscraper_type)->toBe('box-2D');
});

it('orders the games in a collection by the position the curator chose', function () {
    $collection = GameCollection::factory()->create();
    $first = Game::factory()->create();
    $second = Game::factory()->create();

    $collection->games()->attach([$second->id => ['position' => 1], $first->id => ['position' => 0]]);

    expect($collection->games->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($first->collections)->toHaveCount(1);
});

it('resolves a console folder by convention and lets a row override it', function () {
    $psx = new Console('psx');

    expect(ConsoleSourceFolder::pathFor($psx))->toBe('psx');

    ConsoleSourceFolder::create(['console' => 'psx', 'path' => '/roms/playstation/']);

    expect(ConsoleSourceFolder::pathFor($psx))->toBe('roms/playstation');
});

it('stores runtime settings as json and reads them back typed', function () {
    expect(AppSetting::enabled(AppSetting::AUTO_QUEUE_MEDIA_SCRAPE))->toBeTrue();

    AppSetting::put(AppSetting::AUTO_QUEUE_MEDIA_SCRAPE, false);

    $rows = function (): int {
        return AppSetting::query()->where('key', AppSetting::AUTO_QUEUE_MEDIA_SCRAPE)->count();
    };

    expect(AppSetting::enabled(AppSetting::AUTO_QUEUE_MEDIA_SCRAPE))->toBeFalse()
        ->and($rows())->toBe(1);

    AppSetting::put(AppSetting::AUTO_QUEUE_MEDIA_SCRAPE, true);

    expect($rows())->toBe(1);
});

it('lists only the media types switched on', function () {
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D', 'ss']);

    expect(MediaTypes::enabled())->toBe(['box-2D', 'ss']);
});

it('fetches the shipped selection until somebody chooses otherwise', function () {
    // Nothing stored is not the same as nothing switched on: a fresh install
    // fetches the defaults, an emptied list fetches nothing.
    expect(MediaTypes::enabled())->toBe(config('media_types.default_enabled'));

    AppSetting::put(AppSetting::MEDIA_TYPES, []);

    expect(MediaTypes::enabled())->toBe([]);
});

it('reads back a setting written in the same request', function () {
    // The memo is written through rather than invalidated; reading the old
    // value here is the exact bug a naive one introduces.
    expect(AppSetting::enabled(AppSetting::UI_SCANLINES))->toBeTrue();

    AppSetting::put(AppSetting::UI_SCANLINES, false);

    expect(AppSetting::enabled(AppSetting::UI_SCANLINES))->toBeFalse();
});

it('resolves a game back to its console value object', function () {
    $game = Game::factory()->forConsole('dreamcast')->create();

    expect($game->console()->name)->toBe('Dreamcast')
        ->and($game->console()->screenscraperId)->toBe(23);
});
