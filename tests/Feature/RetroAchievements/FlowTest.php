<?php

use App\Enums\GameStatus;
use App\Enums\RetroAchievementsStatus;
use App\Jobs\HashFile;
use App\Jobs\MatchGame;
use App\Jobs\RetroAchievements\HashGame;
use App\Jobs\RetroAchievements\IdentifyGame;
use App\Jobs\RetroAchievements\SyncRecentUnlocks;
use App\Models\Game;
use App\Models\GameFile;
use App\Services\GameMatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('screenscraper.user', 'tester');
    config()->set('screenscraper.password', 'secret');
    config()->set('screenscraper.min_interval', 0);
});

function ssHit(int $providerId = 4242): void
{
    Http::fake(['*' => Http::response([
        'response' => ['jeu' => [
            'id' => $providerId,
            'noms' => [['region' => 'wor', 'text' => 'Super Mario World']],
            'medias' => [],
        ]],
    ], 200)]);
}

function ssMiss(): void
{
    Http::fake(['*' => Http::response(
        mb_convert_encoding('Erreur : Jeu non trouvée !', 'ISO-8859-1', 'UTF-8'), 404
    )]);
}

function matchableGame(): Game
{
    $game = Game::factory()->forConsole('snes')->create();

    GameFile::factory()->for($game)->hashed()->create();

    return $game;
}

it('asks RetroAchievements after a successful match', function () {
    Queue::fake();
    ssHit();

    $game = matchableGame();

    (new MatchGame($game->id))->handle(app(GameMatcher::class));

    Queue::assertPushed(IdentifyGame::class, fn (IdentifyGame $job) => $job->gameId === $game->id);
});

it('asks RetroAchievements even when ScreenScraper has never heard of it', function () {
    Queue::fake();
    ssMiss();

    $game = matchableGame();

    (new MatchGame($game->id))->handle(app(GameMatcher::class));

    expect($game->refresh()->status)->toBe(GameStatus::Unmatched);

    // The two providers answer different questions. Tying them together would
    // lose exactly these games: Unmatched is never re-queued for ScreenScraper,
    // so they would never reach RetroAchievements at all.
    Queue::assertPushed(IdentifyGame::class, fn (IdentifyGame $job) => $job->gameId === $game->id);
});

it('waits for the checksums before asking', function () {
    Queue::fake();
    ssMiss();

    $game = Game::factory()->forConsole('snes')->create();
    GameFile::factory()->for($game)->create();

    (new MatchGame($game->id))->handle(app(GameMatcher::class));

    // That outcome chains a hash and dispatches this job again, so asking now
    // would double up on work that is already coming back.
    Queue::assertNotPushed(IdentifyGame::class);
    Queue::assertPushed(HashFile::class);
});

it('hands the RetroAchievements identification to the surviving game on a merge', function () {
    Queue::fake();
    ssHit(4242);

    $survivor = Game::factory()->forConsole('snes')->matched(4242)->create();

    $loser = matchableGame();
    $loser->update([
        'retroachievements_id' => 4111,
        'retroachievements_status' => RetroAchievementsStatus::Matched,
        'retroachievements_matched_at' => now(),
    ]);

    (new MatchGame($loser->id))->handle(app(GameMatcher::class));

    // The files carry their hashes across, but the identification lives on the
    // game — and the game holding it is the one being deleted.
    expect(Game::find($loser->id))->toBeNull()
        ->and($survivor->refresh()->retroachievements_id)->toBe(4111)
        ->and($survivor->retroachievements_status)->toBe(RetroAchievementsStatus::Matched);
});

it('keeps the long jobs off the short connection', function () {
    // The jobs table has no connection column: a job is re-reserved after the
    // retry_after of whichever connection's worker popped it, so queue names
    // are the only isolation there is. Leaving these on `media` meant a
    // 90-second worker picking up an hour-long job.
    expect((new HashFile(1))->queue)->toBe('hash')
        ->and((new HashFile(1))->connection)->toBe('database-long')
        ->and((new HashGame(1))->queue)->toBe('ra-hash')
        ->and((new HashGame(1))->connection)->toBe('database-long')
        ->and((new IdentifyGame(1))->queue)->toBe('ra')
        ->and((new SyncRecentUnlocks(1))->queue)->toBe('ra-progress');
});
