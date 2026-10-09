<?php

use App\Enums\FileRole;
use App\Events\GameUpdated;
use App\Events\SystemUpdated;
use App\Jobs\HashFile;
use App\Jobs\MatchGame;
use App\Jobs\ScrapeGameMedia;
use App\Models\AppSetting;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\RaGame;
use App\Models\User;
use App\Services\GameMatcher;
use App\Services\GameScorer;
use App\Services\MediaLibrary;
use App\Services\ScreenScraperService;
use App\Support\ExportProgress;
use App\Support\LibraryStorage;
use App\Support\LiveUpdates;
use App\Support\ScreenScraperQuota;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Signals instead of polls. Every page that used to ask on a timer now waits
 * for one of two events, and these tests pin down who sends which, and that a
 * signal can never fail the work that sent it. See
 * docs/adr/0002-live-updates-over-reverb.md.
 */

beforeEach(function () {
    Event::fake([GameUpdated::class, SystemUpdated::class]);
});

/** A jeuInfos answer for one game, with one cover. */
function liveAnswer(): array
{
    return ['response' => ['jeu' => [
        'id' => '19256',
        'noms' => [['region' => 'ss', 'text' => 'Final Fantasy IX']],
        'note' => ['text' => '17'],
        'medias' => [[
            'type' => 'box-2D', 'parent' => 'jeu', 'region' => 'us', 'format' => 'png',
            'url' => 'https://api.screenscraper.fr/api2/mediaJeu.php?jeuid=19256&media=box-2D',
            'md5' => md5("\x89PNG\r\n\x1a\ncover"),
        ]],
    ]]];
}

/** Reverb as the broadcaster, with keys, so auth can sign and a send can be attempted. */
function useReverb(int $port = 8080): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '1',
        'broadcasting.connections.reverb.options.host' => '127.0.0.1',
        'broadcasting.connections.reverb.options.port' => $port,
    ]);
}

it('tells the game page when the lookup is over', function () {
    Bus::fake();
    Http::fake(['*' => Http::response(liveAnswer(), 200)]);

    $game = Game::factory()->forConsole('psx')->create();
    GameFile::factory()->for($game)->create(['path' => 'psx/a.bin', 'filename' => 'a.bin', 'extension' => 'bin', 'role' => FileRole::Track]);

    (new MatchGame($game->id))->handle(app(GameMatcher::class));

    Event::assertDispatched(GameUpdated::class, fn (GameUpdated $e): bool => $e->gameId === $game->id && $e->what === GameUpdated::IDENTIFIED);
});

it('tells the game page when its score changes', function () {
    $set = RaGame::factory()->synced()->create(['num_distinct_players' => 5000]);
    $game = Game::factory()->forConsole('psx')->matched(19256)->create(['retroachievements_id' => $set->id]);

    app(GameScorer::class)->score($game);

    Event::assertDispatched(GameUpdated::class, fn (GameUpdated $e): bool => $e->gameId === $game->id && $e->what === GameUpdated::RATING);
});

it('says nothing when the score has not moved', function () {
    $game = Game::factory()->forConsole('psx')->matched(19256)->create();

    app(GameScorer::class)->score($game);

    Event::assertNotDispatched(GameUpdated::class, fn (GameUpdated $e): bool => $e->what === GameUpdated::RATING);
});

it('tells the game page when the artwork is in', function () {
    Storage::fake('media');
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);
    Http::fake(['*' => Http::response("\x89PNG\r\n\x1a\ncover", 200)]);
    $game = Game::factory()->forConsole('psx')->matched(19256)->create();

    (new ScrapeGameMedia($game->id, liveAnswer()['response']['jeu']['medias']))
        ->handle(app(ScreenScraperService::class), app(MediaLibrary::class));

    Event::assertDispatched(GameUpdated::class, fn (GameUpdated $e): bool => $e->gameId === $game->id && $e->what === GameUpdated::ARTWORK);
});

it('tells the identify form when the checksums are in', function () {
    $root = sys_get_temp_dir().'/retrobite-live-'.Str::random(8);
    File::ensureDirectoryExists($root.'/psx');
    File::put($root.'/psx/a.bin', 'bytes');
    config()->set('settings.games_path', $root);

    $game = Game::factory()->forConsole('psx')->create();
    $file = GameFile::factory()->for($game)->create(['path' => 'psx/a.bin', 'filename' => 'a.bin', 'extension' => 'bin', 'role' => FileRole::Track]);

    try {
        (new HashFile($file->id))->handle();
    } finally {
        File::deleteDirectory($root);
    }

    Event::assertDispatched(GameUpdated::class, fn (GameUpdated $e): bool => $e->gameId === $game->id && $e->what === GameUpdated::HASHED);
});

it('tells the page when a job for its game gives up', function () {
    // Without this a banner would wait out its whole timeout for an answer
    // that is never coming.
    $game = Game::factory()->forConsole('psx')->create();
    $file = GameFile::factory()->for($game)->create(['path' => 'psx/a.bin', 'filename' => 'a.bin', 'extension' => 'bin', 'role' => FileRole::Track]);

    (new MatchGame($game->id))->failed(new RuntimeException('no'));
    (new HashFile($file->id))->failed(new RuntimeException('no'));

    Event::assertDispatchedTimes(GameUpdated::class, 2);
    Event::assertDispatched(GameUpdated::class, fn (GameUpdated $e): bool => $e->gameId === $game->id && $e->what === GameUpdated::FAILED);
});

it('tells the sidebar when the allowance, an export or the disk changes', function () {
    ScreenScraperQuota::remember(['ssuser' => ['maxthreads' => 1, 'requeststoday' => 5]]);
    Event::assertDispatched(SystemUpdated::class, fn (SystemUpdated $e): bool => $e->what === SystemUpdated::QUOTA);

    ExportProgress::advance('ps2', 'cfg', 1, 19);
    ExportProgress::finish('ps2', 'cfg');
    Event::assertDispatchedTimes(SystemUpdated::class, 3);

    LibraryStorage::changed();
    Event::assertDispatched(SystemUpdated::class, fn (SystemUpdated $e): bool => $e->what === SystemUpdated::STORAGE);
});

it('tells the sidebar the queues moved, for any job at all', function () {
    // A job the interface never saw — the command line, the scheduler — is
    // exactly the one the old page-side nudge missed.
    dispatch(function (): void {});

    Event::assertDispatched(SystemUpdated::class, fn (SystemUpdated $e): bool => $e->what === SystemUpdated::ACTIVITY);
});

it('never fails the work when Reverb cannot be reached', function () {
    // The real dispatcher back (beforeEach faked it), and a port nothing
    // listens on.
    Event::swap(Event::getFacadeRoot()->dispatcher);
    useReverb(port: 1);
    Log::spy();

    LiveUpdates::game(1, GameUpdated::ARTWORK);
    LiveUpdates::system(SystemUpdated::ACTIVITY);

    // Said once a minute, not once per signal: an artwork run would otherwise
    // write a warning for every game.
    Log::shouldHaveReceived('warning')->once();
});

it('lets only a signed-in user onto the channels', function () {
    useReverb();

    // Channels are registered on whichever broadcaster was the default at
    // boot — the suite's null one. Registered again on Reverb's.
    Broadcast::forgetDrivers();
    require base_path('routes/channels.php');

    $this->post('/broadcasting/auth', ['channel_name' => 'private-system', 'socket_id' => '1234.5678'])
        ->assertForbidden();

    $this->actingAs(User::factory()->create());

    $this->post('/broadcasting/auth', ['channel_name' => 'private-system', 'socket_id' => '1234.5678'])
        ->assertOk()->assertJsonStructure(['auth']);
    $this->post('/broadcasting/auth', ['channel_name' => 'private-games.5', 'socket_id' => '1234.5678'])
        ->assertOk()->assertJsonStructure(['auth']);
});

it('hands the browser the key at runtime, and only when signed in', function () {
    useReverb();

    $this->actingAs(User::factory()->create());
    $this->get(route('dashboard'))->assertOk()->assertSee('<meta name="reverb-key" content="test-key" />', escape: false);

    auth()->logout();
    $this->get(route('login'))->assertDontSee('reverb-key');
});

it('tells the sidebar once for a burst of jobs queued, and once more after it', function () {
    // A backfill queuing five thousand lookups was five thousand broadcasts
    // in two seconds, and Reverb stopped under them.
    for ($i = 0; $i < 5000; $i++) {
        dispatch(fn () => null)->onConnection('database');
    }

    // One a second at most, however long the loop takes.
    $during = Event::dispatched(SystemUpdated::class)->count();
    expect($during)->toBeGreaterThanOrEqual(1)->toBeLessThan(10);

    // And the one that counts after the last of them, as the process ends.
    LiveUpdates::flush();

    Event::assertDispatchedTimes(SystemUpdated::class, $during + 1);
});
