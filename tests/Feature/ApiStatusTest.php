<?php

use App\Models\User;
use App\Support\ScreenScraperQuota;
use Livewire\Livewire;

/**
 * The sidebar panel. Mostly about the states that are easy to get wrong —
 * "never asked" read as a spent allowance, and the scarce allowance dropped.
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

    Livewire::test('api-status')
        ->assertOk()
        ->assertViewHas('scraperAccount', false)
        ->assertViewHas('requests', null);
});

it('tells "nothing asked yet" apart from a spent allowance', function () {
    config(['screenscraper.user' => 'someone']);

    // No snapshot: the account is fine, nothing has been looked up. A bar at
    // zero percent here would read as a blocked quota.
    Livewire::test('api-status')
        ->assertViewHas('scraperAccount', true)
        ->assertViewHas('quota', null)
        ->assertViewHas('requests', null)
        ->assertViewHas('failed', null);
});

it('reads what has been spent of both allowances, not just the large one', function () {
    recordQuota();

    // 15 000 of 20 000 successful. Spent rather than left, so the figure and
    // the bar under it move the same way — they used to disagree.
    //
    // … and 1 900 of 2 000 failed, which is the one that runs out first.
    Livewire::test('api-status')
        ->assertViewHas('requests', ['used' => 15000, 'max' => 20000])
        ->assertViewHas('failed', ['used' => 1900, 'max' => 2000])
        ->assertViewHas('closed', false);
});

it('warns when the server is turning accounts like ours away', function () {
    recordQuota(servers: ['closeforleecher' => 1]);

    Livewire::test('api-status')->assertViewHas('closed', true);
});

it('re-reads the figures on every render rather than freezing at page load', function () {
    recordQuota();

    $panel = Livewire::test('api-status')->assertViewHas('requests', ['used' => 15000, 'max' => 20000]);

    // A worker spends another four thousand lookups while the page sits open.
    recordQuota(['requeststoday' => 19000]);

    // What the quota signal asks for, and the reason this is a component at all.
    $panel->call('$refresh')
        ->assertViewHas('requests', ['used' => 19000, 'max' => 20000]);
});
