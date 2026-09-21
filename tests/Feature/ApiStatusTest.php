<?php

use App\Models\AppSetting;
use App\Models\RaConsoleSync;
use App\Models\User;
use App\Support\ScreenScraperQuota;
use Livewire\Livewire;

/**
 * The sidebar panel. Mostly about the states that are easy to get wrong —
 * "never asked" drawn as a spent allowance, a day-old figure drawn as today's,
 * and the scarce allowance folded away without being dropped.
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
        ->assertDontSee('20,000');
});

it('draws what is left of both allowances, not just the large one', function () {
    recordQuota();

    $response = $this->get(route('dashboard'))->assertOk();

    // 20 000 - 15 000 successful …
    $response->assertSee('5,000')->assertSee('20,000');
    // … and 2 000 - 1 900 failed, which is the one that runs out first. Folded
    // away by default, but rendered: the fold is the viewer's, not the
    // server's, so opening it costs no round trip.
    $response->assertSee('100')->assertSee('2,000');
});

it('carries the figure on the heading row rather than spending a line on a title', function () {
    recordQuota();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Scraper')
        // The section title this replaces, and the footnote under the bars.
        ->assertDontSee('APIs')
        ->assertDontSee('level 2');
});

it('folds the failed allowance away until it is asked for', function () {
    recordQuota();

    // Asserted against the component rather than the page: the activity block
    // below it has a fold of its own, and a page-wide assertion would pass on
    // that one and say nothing about this.
    Livewire::test('api-status')
        ->assertSeeHtml('x-show="open"')
        ->assertSeeHtml('sidebar.scraper')
        // Rendered, not withheld — the fold is the viewer's, so opening it
        // costs no round trip.
        ->assertSee('Failed');
});

it('offers nothing to unfold when there is no snapshot to unfold', function () {
    config(['screenscraper.user' => 'someone']);

    // A dead control in a column this narrow is worse than no control, so
    // neither the fold nor the chevron that opens it is drawn. Unlike the
    // activity block, which always has every queue to show.
    Livewire::test('api-status')
        ->assertDontSeeHtml('x-show="open"')
        ->assertDontSeeHtml('-rotate-180');
});

it('says nothing about RetroAchievements, which reports no allowance at all', function () {
    AppSetting::putSecret(AppSetting::RA_API_KEY, 'key');
    test()->user->forceFill([
        'retroachievements_username' => 'someone',
        'retroachievements_synced_at' => now()->subMinutes(12),
    ])->save();

    RaConsoleSync::create(['ra_console_id' => 3, 'synced_at' => now()->subHours(9), 'games' => 5, 'hashes' => 9]);

    // Freshness is a real question and this is no longer where it is answered.
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('RetroAchievements')
        ->assertDontSee('Hash index')
        ->assertDontSee('Sets waiting');
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
