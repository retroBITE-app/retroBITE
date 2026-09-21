<?php

use App\Models\AppSetting;
use App\Models\Game;
use App\Models\RaConsoleSync;
use App\Models\RaGame;
use App\Models\User;
use App\Support\ScreenScraperQuota;
use Livewire\Livewire;

/**
 * The sidebar panel. Mostly about the states that are easy to get wrong —
 * "never asked" drawn as a spent allowance, a day-old figure drawn as today's,
 * and a RetroAchievements quota that does not exist.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * @param  array<string, mixed>  $user
 * @param  array<string, mixed>  $servers
 */
function recordQuota(array $user = [], array $servers = []): void
{
    ScreenScraperQuota::remember([
        'ssuser' => [
            'maxthreads' => 1,
            'requeststoday' => 15000,
            'maxrequestsperday' => 20000,
            'requestskotoday' => 1900,
            'maxrequestskoperday' => 2000,
            'niveau' => 2,
            ...$user,
        ],
        'serveurs' => $servers,
    ]);
}

it('says there is no account rather than drawing an empty allowance', function () {
    config(['screenscraper.user' => '']);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('No account set. Scanning works; identifying does not.');
});

it('tells "nothing asked yet" apart from a spent allowance', function () {
    config(['screenscraper.user' => 'someone']);

    // No snapshot: the account is fine, nothing has been looked up. A bar at
    // zero percent here would read as a blocked quota.
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Not asked yet — figures appear after the first lookup.')
        ->assertDontSee('Requests');
});

it('draws what is left of both allowances, not just the large one', function () {
    recordQuota();

    $response = $this->get(route('dashboard'))->assertOk();

    // 20 000 - 15 000 successful …
    $response->assertSee('5,000')->assertSee('20,000');
    // … and 2 000 - 1 900 failed, which is the one that runs out first.
    $response->assertSee('100')->assertSee('2,000');
});

it('colours each bar by how close that counter is to its own limit', function () {
    recordQuota();

    // Requests at 75 per cent, failures at 95: the two must not share a
    // reading, which is exactly what one combined bar would give them.
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('bg-warn', false)
        ->assertSee('bg-danger', false);
});

it('dates the figures, because the snapshot does not age by itself', function () {
    $this->travelTo(now()->subHours(3), fn () => recordQuota());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('3h ago');
});

it('says when a counter came back without a limit', function () {
    recordQuota(['maxrequestskoperday' => 0]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('not reported');
});

it('warns when the server is turning accounts like ours away', function () {
    recordQuota(servers: ['closeforleecher' => 1]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Turning away accounts that only download.');
});

it('offers the settings screen when RetroAchievements is not linked', function () {
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('not linked')
        ->assertSee(route('retroachievements.edit'), false);
});

it('answers freshness for RetroAchievements, which reports no allowance', function () {
    AppSetting::putSecret(AppSetting::RA_API_KEY, 'key');
    $this->user->forceFill([
        'retroachievements_username' => 'someone',
        'retroachievements_synced_at' => now()->subMinutes(12),
    ])->save();

    Game::factory()->forConsole('snes')->create();
    RaConsoleSync::create([
        'ra_console_id' => 3,
        'synced_at' => now()->subHours(9),
        'games' => 5,
        'hashes' => 9,
    ]);
    RaGame::factory()->count(2)->create();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Hash index')
        ->assertSee('1/1')
        ->assertSee('9h ago')
        ->assertSee('12m ago')
        // Identified games whose set has not been fetched yet.
        ->assertSee('Sets waiting')
        // And no invented quota anywhere in the RetroAchievements half.
        ->assertDontSee('Requests');
});

it('counts the consoles the index is missing, not the ones it has', function () {
    AppSetting::putSecret(AppSetting::RA_API_KEY, 'key');
    $this->user->forceFill(['retroachievements_username' => 'someone'])->save();

    Game::factory()->forConsole('snes')->create();
    Game::factory()->forConsole('nes')->create();

    // Only one of the two consoles has been indexed, and the oldest sync is
    // what the panel reports — the weakest link decides what can be identified.
    RaConsoleSync::create(['ra_console_id' => 3, 'synced_at' => now()->subDay(), 'games' => 5, 'hashes' => 9]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('1/2')
        ->assertSee('1d ago');
});

it('re-reads the figures on every poll rather than freezing at page load', function () {
    recordQuota();

    $panel = Livewire::test('api-status')->assertSee('5,000');

    // A worker spends another four thousand lookups while the page sits open.
    recordQuota(['requeststoday' => 19000]);

    // What wire:poll.60s asks for, and the reason this is a component at all.
    $panel->call('$refresh')
        ->assertSee('1,000')
        ->assertDontSee('5,000');
});
