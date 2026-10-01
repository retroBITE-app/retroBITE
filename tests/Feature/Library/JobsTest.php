<?php

use App\Jobs\HashFile;
use App\Jobs\MatchGame;
use App\Jobs\PruneGame;
use App\Jobs\RateGame;
use App\Jobs\RetroAchievements\HashGame;
use App\Jobs\RetroAchievements\IdentifyGame;
use App\Jobs\RetroAchievements\ReconcileProgress;
use App\Jobs\RetroAchievements\SyncGameProgress;
use App\Jobs\RetroAchievements\SyncHashIndex;
use App\Jobs\RetroAchievements\SyncRecentUnlocks;
use App\Jobs\RetroAchievements\SyncSet;
use App\Jobs\RunConversion;
use App\Jobs\ScanConsoleFolder;
use App\Jobs\ScrapeGameMedia;
use App\Models\Game;
use App\Services\ScreenScraperService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Pushing onto a real queue, not a fake one.
 *
 * Queue::fake() and the sync driver both skip the path that serialises a job,
 * and that path asks the job for things — backoff(), retryUntil(), uniqueId().
 * A private method whose name matches one of those is fatal when the framework
 * reaches for it, and every test that faked the queue said the job was fine.
 */
it('can be pushed onto a real queue', function (string $job, array $arguments) {
    config()->set('queue.default', 'database');
    DB::table('jobs')->delete();

    $job::dispatch(...$arguments);

    expect(DB::table('jobs')->count())->toBe(1);
})->with([
    'match' => [MatchGame::class, [1]],
    'scrape media' => [ScrapeGameMedia::class, [1, []]],
    'scan' => [ScanConsoleFolder::class, ['snes']],
    'hash' => [HashFile::class, [1]],
    'rate' => [RateGame::class, [1]],

    // The RetroAchievements jobs are the first in this codebase to implement
    // ShouldBeUnique, so they are the first whose uniqueId() the framework
    // calls during dispatch — which is exactly the kind of thing this test
    // exists to catch.
    'ra index' => [SyncHashIndex::class, [3]],
    'ra hash' => [HashGame::class, [1]],
    'ra identify' => [IdentifyGame::class, [1]],
    'ra set' => [SyncSet::class, [1]],
    'ra recent' => [SyncRecentUnlocks::class, [1]],
    'ra reconcile' => [ReconcileProgress::class, [1]],
    'ra game progress' => [SyncGameProgress::class, [1, 1]],

    // On database-long rather than the default connection, which is the
    // same jobs table and so still counts here.
    'conversion' => [RunConversion::class, [1]],

    'prune' => [PruneGame::class, [1, 30]],
]);

/*
 * The rating backfill.
 *
 * It exists only because a library identified before ratings existed would
 * otherwise never show one, and every one of its requests is a successful
 * lookup against the daily allowance — so what it does not ask about matters
 * as much as what it writes.
 */

it('asks by the provider id and writes nothing but the rating', function () {
    Http::fake(['*' => Http::response(['response' => ['jeu' => [
        'id' => '19256',
        'noms' => [['region' => 'ss', 'text' => 'Final Fantasy IX']],
        'note' => ['text' => '18'],
        'editeur' => ['text' => 'Somebody Else'],
    ]]], 200)]);

    $game = Game::factory()->matched(19256)->create([
        'title' => 'FF9', 'slug' => 'ff9', 'publisher' => 'Square',
    ]);

    (new RateGame($game->id))->handle(app(ScreenScraperService::class));

    $game->refresh();

    // A backfill is not a re-identification: the answer carries a whole
    // record and only one column of it is ours to take.
    expect($game->rating)->toBe(90)
        ->and($game->title)->toBe('FF9')
        ->and($game->slug)->toBe('ff9')
        ->and($game->publisher)->toBe('Square');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'gameid=19256'));
});

it('spends nothing on a game that already has a rating', function () {
    Http::preventStrayRequests();

    $game = Game::factory()->matched(19256)->rated(80)->create();

    (new RateGame($game->id))->handle(app(ScreenScraperService::class));

    Http::assertNothingSent();
    expect($game->refresh()->rating)->toBe(80);
});

it('leaves a game with no rating to be asked about again', function () {
    Http::fake(['*' => Http::response(['response' => ['jeu' => [
        'id' => '19256',
        'noms' => [['region' => 'ss', 'text' => 'Final Fantasy IX']],
    ]]], 200)]);

    $game = Game::factory()->matched(19256)->create();

    (new RateGame($game->id))->handle(app(ScreenScraperService::class));

    // Nothing is written and nothing is marked, so scopeAwaitingRating still
    // holds it. Ratings are votes and they accumulate.
    expect($game->refresh()->rating)->toBeNull()
        ->and(Game::query()->awaitingRating()->count())->toBe(1);
});

it('writes over a rating it already holds when it was asked again', function () {
    Http::fake(['*' => Http::response(['response' => ['jeu' => [
        'id' => '19256',
        'noms' => [['region' => 'ss', 'text' => 'Final Fantasy IX']],
        'note' => ['text' => '18'],
    ]]], 200)]);

    $game = Game::factory()->matched(19256)->rated(40)->create();

    // The guard that makes the backfill safe to run twice is the one thing
    // force is for. Votes accumulate, so the second answer is the better one.
    (new RateGame($game->id, force: true))->handle(app(ScreenScraperService::class));

    expect($game->refresh()->rating)->toBe(90);
});

it('queues only identified games that have no rating yet', function () {
    Queue::fake();

    Game::factory()->matched()->create(['console' => 'snes']);
    Game::factory()->matched()->rated(80)->create(['console' => 'snes']);
    Game::factory()->create(['console' => 'snes']);              // a placeholder
    Game::factory()->matched()->create(['console' => 'psx']);

    expect(RateGame::queueAwaiting('snes'))->toBe(1);

    Queue::assertPushed(RateGame::class, 1);
});

it('goes back on the queue rather than failing when the quota is spent', function () {
    Http::fake(['*' => Http::response(
        mb_convert_encoding(
            'Erreur : Vous avez atteint votre quota de scrape journalier !',
            'ISO-8859-1',
            'UTF-8',
        ),
        430,
    )]);

    $game = Game::factory()->matched(19256)->create();

    $job = (new RateGame($game->id))->withFakeQueueInteractions();
    $job->handle(app(ScreenScraperService::class));

    // A spent allowance is not this game's fault and not worth an attempt:
    // every other game in the backfill would get the same answer.
    $job->assertReleased();
    $job->assertNotFailed();

    expect($game->refresh()->rating)->toBeNull();
});

it('queues every identified game on a console when asked to start over', function () {
    Queue::fake();

    $bare = Game::factory()->matched()->create(['console' => 'snes']);
    $rated = Game::factory()->matched()->rated(80)->create(['console' => 'snes']);
    Game::factory()->create(['console' => 'snes']);              // a placeholder
    Game::factory()->matched()->create(['console' => 'psx']);

    expect(RateGame::queueForConsole('snes', held: true))->toBe(2);

    // Forced, or the job would drop the one that already has a rating — which
    // is the very game somebody starting over means.
    Queue::assertPushed(RateGame::class, 2);
    Queue::assertPushed(RateGame::class, fn ($job) => $job->gameId === $rated->id && $job->force === true);
    Queue::assertPushed(RateGame::class, fn ($job) => $job->gameId === $bare->id && $job->force === true);
});

it('fills only the gaps on a console when not asked to start over', function () {
    Queue::fake();

    $bare = Game::factory()->matched()->create(['console' => 'snes']);
    Game::factory()->matched()->rated(80)->create(['console' => 'snes']);

    expect(RateGame::queueForConsole('snes'))->toBe(1);

    Queue::assertPushed(RateGame::class, fn ($job) => $job->gameId === $bare->id && $job->force === false);
});
