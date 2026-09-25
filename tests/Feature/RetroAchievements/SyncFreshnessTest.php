<?php

use App\Models\RaGame;
use App\Support\RetroAchievements\SyncFreshness;
use Illuminate\Support\Defer\DeferredCallbackCollection;

/*
 * The sidebar's RetroAchievements figures are cached stale-while-revalidate:
 * no page waits on the queries behind them, even the one that finds them old.
 */

it('hands back the old figure at once and refreshes it behind the response', function () {
    RaGame::factory()->create(); // one set not yet downloaded

    expect(SyncFreshness::current()['sets_pending'])->toBe(1);

    RaGame::factory()->create();

    // Still fresh: the cached figure, no queries.
    expect(SyncFreshness::current()['sets_pending'])->toBe(1);

    $this->travel(2)->minutes();

    // Past fresh, within stale: the old figure straight away…
    expect(SyncFreshness::current()['sets_pending'])->toBe(1);

    // …and the new one once the deferred refresh has run after the response.
    app(DeferredCallbackCollection::class)->invoke();

    expect(SyncFreshness::current()['sets_pending'])->toBe(2);
});
