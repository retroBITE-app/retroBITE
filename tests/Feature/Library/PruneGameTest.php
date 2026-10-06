<?php

use App\Enums\ConversionStatus;
use App\Jobs\PruneGame;
use App\Models\Conversion;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Services\MediaLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
 * Pruning: the command finds the games worth asking about, and each game's
 * own job forgets its ROMs missing past the cutoff, and the game once it has
 * none — or never had any.
 */

beforeEach(function () {
    Storage::fake('media');
});

/**
 * A game whose files went missing that many days ago, null for one still on
 * the disk.
 *
 * @param  list<int|null>  $missingDaysAgo
 */
function gameMissingFor(array $missingDaysAgo, string $console = 'snes'): Game
{
    $game = Game::factory()->forConsole($console)->create();

    foreach ($missingDaysAgo as $days) {
        GameFile::factory()->for($game)->create([
            'path' => $console.'/'.fake()->unique()->slug().'.sfc',
            'missing_since' => $days === null ? null : now()->subDays($days),
        ]);
    }

    return $game;
}

function pruneGame(Game $game, int $days = 30): void
{
    (new PruneGame($game->id, $days))->handle(app(MediaLibrary::class));
}

it('removes a game whose only ROM has been missing past the cutoff, with its artwork', function () {
    $game = gameMissingFor([40]);
    $media = Media::factory()->for($game)->create(['path' => "snes/{$game->slug}-{$game->id}/box-2d/cover.png"]);
    Storage::disk('media')->put($media->path, 'png');

    pruneGame($game);

    expect(Game::query()->find($game->id))->toBeNull()
        ->and(GameFile::query()->where('game_id', $game->id)->count())->toBe(0)
        ->and(Media::query()->where('game_id', $game->id)->count())->toBe(0)
        ->and(Storage::disk('media')->exists($media->path))->toBeFalse()
        ->and(Storage::disk('media')->exists("snes/{$game->slug}-{$game->id}"))->toBeFalse();
});

it('takes only the missing entry off a game that still has another ROM', function () {
    $game = gameMissingFor([40, null]);

    pruneGame($game);

    expect(Game::query()->find($game->id))->not->toBeNull()
        ->and($game->files()->count())->toBe(1)
        ->and($game->files()->first()->missing_since)->toBeNull();
});

it('removes a game once every one of its ROMs is past the cutoff', function () {
    $game = gameMissingFor([40, 35]);

    pruneGame($game);

    expect(Game::query()->find($game->id))->toBeNull();
});

it('keeps a ROM gone only lately, and the game it belongs to', function () {
    $game = gameMissingFor([40, 10]);

    pruneGame($game);

    expect($game->files()->count())->toBe(1)
        ->and(Game::query()->find($game->id))->not->toBeNull();
});

it('removes a ROM missing only moments ago at 0 days, and the game left without any', function () {
    $game = gameMissingFor([0]);

    pruneGame($game, 0);

    expect(Game::query()->find($game->id))->toBeNull();
});

it('removes a game that never had a ROM, whatever the days', function () {
    $empty = Game::factory()->forConsole('snes')->create();

    pruneGame($empty, 3650);

    expect(Game::query()->find($empty->id))->toBeNull();
});

it('leaves a game alone while a conversion of it is still queued', function () {
    $game = gameMissingFor([40]);
    $file = $game->files()->first();

    Conversion::query()->create([
        'console' => 'snes', 'converter' => 'cso', 'game_id' => $game->id, 'game_file_id' => $file->id,
        'label' => 'Game', 'sources' => [$file->path], 'options' => [], 'queued_at' => now(),
        'status' => ConversionStatus::Queued,
    ]);

    pruneGame($game);

    expect(GameFile::query()->find($file->id))->not->toBeNull()
        ->and(Game::query()->find($game->id))->not->toBeNull();
});

it('does nothing for a game already gone', function () {
    $game = gameMissingFor([40]);
    $game->delete();

    pruneGame($game);

    expect(Activity::query()->where('log_name', 'library')->count())->toBe(0);
});

it('says what it removed in the activity log', function () {
    $game = gameMissingFor([40]);

    pruneGame($game);

    $entry = Activity::query()->where('log_name', 'library')->sole();

    expect($entry->description)->toBe('pruned')
        ->and($entry->properties['game'])->toBe('snes: '.$game->title)
        ->and($entry->properties['files'])->toBe(1)
        ->and($entry->properties['removed'])->toBeTrue();
});

it('queues a prune for games with no ROMs and games with ROMs missing, and none for the rest', function () {
    $empty = Game::factory()->forConsole('snes')->create();
    $missing = gameMissingFor([2, null]);
    $healthy = gameMissingFor([null, null]);
    Queue::fake();

    $this->artisan('retrobite:library:prune', ['--days' => 14])
        ->expectsOutputToContain('Queued 1 games with no ROMs and 1 with ROMs missing, at 14 days.')
        ->assertSuccessful();

    Queue::assertPushed(PruneGame::class, 2);
    Queue::assertPushed(PruneGame::class, function (PruneGame $job) use ($empty, $missing): bool {
        return in_array($job->gameId, [$empty->id, $missing->id], true) && $job->days === 14;
    });
    Queue::assertNotPushed(PruneGame::class, function (PruneGame $job) use ($healthy): bool {
        return $job->gameId === $healthy->id;
    });
});

it('queues at 0 days when asked, rather than raising it to one', function () {
    gameMissingFor([0]);
    Queue::fake();

    $this->artisan('retrobite:library:prune', ['--days' => 0])->assertSuccessful();

    Queue::assertPushed(PruneGame::class, function (PruneGame $job): bool {
        return $job->days === 0;
    });
});

it('queues at the saved days when none are given', function () {
    Game::factory()->forConsole('snes')->create();
    Queue::fake();

    $this->artisan('retrobite:library:prune')->assertSuccessful();

    Queue::assertPushed(PruneGame::class, function (PruneGame $job): bool {
        return $job->days === 30;
    });
});

it('queues onto a real queue, on default', function () {
    config()->set('queue.default', 'database');
    DB::table('jobs')->delete();
    Game::factory()->forConsole('snes')->count(3)->create();

    $this->artisan('retrobite:library:prune')->assertSuccessful();

    expect(DB::table('jobs')->where('queue', 'default')->count())->toBe(3);
});
