<?php

use App\Enums\FileRole;
use App\Enums\RetroAchievementsStatus;
use App\Exceptions\RetroAchievements\BadCredentials;
use App\Jobs\RetroAchievements\IdentifyGame;
use App\Jobs\RetroAchievements\SyncHashIndex;
use App\Jobs\RetroAchievements\SyncSet;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\RaGame;
use App\Models\RaGameHash;
use App\Services\RetroAchievementsMatcher;
use App\Services\RetroAchievementsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-ra-id-'.Str::random(8);
    mkdir($this->root.'/snes', recursive: true);
    config()->set('settings.games_path', $this->root);
    config()->set('retroachievements.min_interval', 0);
    config()->set('retroachievements.api_key_fallback', 'test-key');
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
});

/** @param  array<int, array<string, mixed>>  $games */
function raGameList(array $games): void
{
    Http::fake(['*' => Http::response($games, 200)]);
}

/**
 * Several answers in order.
 *
 * Http::fake() merges stubs rather than replacing them, so calling it twice
 * with the same pattern leaves the first answer winning both times — which is
 * the same trap the ScreenScraper tests already carry a helper for.
 *
 * @param  array<int, array<string, mixed>>  ...$pages
 */
function raGameListSequence(array ...$pages): void
{
    $sequence = Http::sequence();

    foreach ($pages as $page) {
        $sequence->push($page, 200);
    }

    Http::fake(['*' => $sequence]);
}

function hashedGame(string $hash, string $console = 'snes'): Game
{
    $game = Game::factory()->forConsole($console)->create([
        'retroachievements_status' => RetroAchievementsStatus::Pending,
    ]);

    $relative = $console.'/'.Str::random(6).'.sfc';
    file_put_contents(test()->root.'/'.$relative, 'rom');

    GameFile::factory()->for($game)->create([
        'path' => $relative,
        'filename' => basename($relative),
        'extension' => 'sfc',
        'role' => FileRole::Rom,
        'ra_hash' => $hash,
        'ra_hash_size' => 3,
        'ra_hash_mtime' => filemtime(test()->root.'/'.$relative),
        'ra_hashed_at' => now(),
    ]);

    return $game;
}

function runIndexSync(int $raConsoleId = 3): void
{
    (new SyncHashIndex($raConsoleId))->handle(
        app(RetroAchievementsService::class),
        app(RetroAchievementsMatcher::class),
    );
}

it('stores the index and the games it came with', function () {
    raGameList([[
        'ID' => 4111, 'Title' => 'Super Mario World', 'ConsoleID' => 3,
        'NumAchievements' => 60, 'Points' => 500,
        'Hashes' => ['AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'],
    ]]);

    runIndexSync();

    expect(RaGame::find(4111)?->title)->toBe('Super Mario World')
        ->and(RaGameHash::count())->toBe(2)
        // Lowercased on the way in, so the join against game_files.ra_hash
        // cannot miss on case alone.
        ->and(RaGameHash::pluck('hash')->all())->toContain('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
});

it('runs twice without duplicating anything', function () {
    $payload = [[
        'ID' => 4111, 'Title' => 'Super Mario World', 'ConsoleID' => 3,
        'NumAchievements' => 60, 'Points' => 500, 'Hashes' => ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
    ]];

    raGameListSequence($payload, $payload);
    runIndexSync();
    runIndexSync();

    expect(RaGame::count())->toBe(1)->and(RaGameHash::count())->toBe(1);
});

it('drops hashes the provider no longer lists, and keeps the game', function () {
    raGameListSequence(
        [[
            'ID' => 4111, 'Title' => 'Super Mario World', 'ConsoleID' => 3,
            'NumAchievements' => 60, 'Points' => 500,
            'Hashes' => ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'],
        ]],
        [[
            'ID' => 4111, 'Title' => 'Super Mario World', 'ConsoleID' => 3,
            'NumAchievements' => 60, 'Points' => 500, 'Hashes' => ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
        ]],
    );

    runIndexSync();
    runIndexSync();

    // The hash went; the set stays. Deleting the game row would cascade a
    // person's progress away over a housekeeping change at their end.
    expect(RaGameHash::count())->toBe(1)->and(RaGame::count())->toBe(1);
});

it('keeps the index when the answer comes back empty', function () {
    raGameListSequence(
        [[
            'ID' => 4111, 'Title' => 'Super Mario World', 'ConsoleID' => 3,
            'NumAchievements' => 60, 'Points' => 500, 'Hashes' => ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
        ]],
        [],
    );

    runIndexSync();
    runIndexSync();

    // An empty answer for a console that had hashes is far likelier to be a
    // bad response than a real emptying, and clearing it would make every game
    // on that console unidentifiable.
    expect(RaGameHash::count())->toBe(1);
});

it('identifies a game whose hash is in the index', function () {
    Queue::fake();

    $game = hashedGame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    RaGame::factory()->create(['id' => 4111, 'ra_console_id' => 3]);
    RaGameHash::factory()->create([
        'hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'ra_game_id' => 4111, 'ra_console_id' => 3,
    ]);

    (new IdentifyGame($game->id))->handle(app(RetroAchievementsMatcher::class));

    expect($game->refresh()->retroachievements_id)->toBe(4111)
        ->and($game->retroachievements_status)->toBe(RetroAchievementsStatus::Matched);

    Queue::assertPushed(SyncSet::class, fn (SyncSet $job) => $job->raGameId === 4111);
});

it('records no match without failing, and picks it up when the set appears', function () {
    $game = hashedGame('cccccccccccccccccccccccccccccccc');

    (new IdentifyGame($game->id))->handle(app(RetroAchievementsMatcher::class));

    expect($game->refresh()->retroachievements_status)->toBe(RetroAchievementsStatus::NoMatch)
        ->and($game->retroachievements_id)->toBeNull();

    // The set turns up later. The hash is already cached, so the nightly index
    // sync identifies it without hashing anything or asking about this game.
    raGameList([[
        'ID' => 9000, 'Title' => 'Later Set', 'ConsoleID' => 3,
        'NumAchievements' => 10, 'Points' => 50, 'Hashes' => ['cccccccccccccccccccccccccccccccc'],
    ]]);
    runIndexSync();

    expect($game->refresh()->retroachievements_id)->toBe(9000)
        ->and($game->retroachievements_status)->toBe(RetroAchievementsStatus::Matched);
});

it('marks a console with no mapping as unsupported', function () {
    $game = Game::factory()->forConsole('ps4')->create();

    (new IdentifyGame($game->id))->handle(app(RetroAchievementsMatcher::class));

    expect($game->refresh()->retroachievements_status)->toBe(RetroAchievementsStatus::Unsupported);
});

it('does not chain hashing for ever when the disk is not mounted', function () {
    Queue::fake();

    $game = Game::factory()->forConsole('snes')->create();

    // A row for a file that is not on disk, which is what an unmounted disk
    // looks like from here.
    GameFile::factory()->for($game)->create([
        'path' => 'snes/gone.sfc', 'filename' => 'gone.sfc', 'extension' => 'sfc',
        'role' => FileRole::Rom,
    ]);

    (new IdentifyGame($game->id))->handle(app(RetroAchievementsMatcher::class));

    // Nothing queued and nothing written: the status has to stay Pending, or
    // remounting the disk would need a manual reset of every game on it.
    Queue::assertNothingPushed();
    expect($game->refresh()->retroachievements_status)->toBe(RetroAchievementsStatus::Pending);
});

it('stops after one hash attempt rather than chaining again', function () {
    Queue::fake();

    $game = hashedGame('dddddddddddddddddddddddddddddddd');
    $game->files()->update(['ra_hash' => null]);

    // The run that follows a hash attempt. Without the flag this would queue
    // another chain, and that chain would queue another.
    (new IdentifyGame($game->id, afterHash: true))->handle(app(RetroAchievementsMatcher::class));

    Queue::assertNothingPushed();
});

it('reports a failure instead of swallowing it when run from a command', function () {
    config()->set('retroachievements.api_key_fallback', '');

    // Off a queue, $this->fail() has no job to act on and returns quietly, so
    // the command printed "synced" and stored nothing. Run from a command,
    // the reason has to come back out.
    expect(fn () => runIndexSync())
        ->toThrow(BadCredentials::class);
});
