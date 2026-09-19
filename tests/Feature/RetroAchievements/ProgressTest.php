<?php

use App\Enums\AwardKind;
use App\Jobs\RetroAchievements\SyncGameProgress;
use App\Jobs\RetroAchievements\SyncRecentUnlocks;
use App\Jobs\RetroAchievements\SyncSet;
use App\Models\RaAchievement;
use App\Models\RaGame;
use App\Models\RaProgress;
use App\Models\RaUnlock;
use App\Models\User;
use App\Services\RetroAchievementsProgress;
use App\Services\RetroAchievementsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('retroachievements.min_interval', 0);
    config()->set('retroachievements.api_key_fallback', 'test-key');

    $this->user = User::factory()->create(['retroachievements_username' => 'tester']);
    $this->set = RaGame::factory()->synced()->create(['id' => 4111]);
    $this->progress = app(RetroAchievementsProgress::class);
});

function achievementWorth(int $points, array $state = []): RaAchievement
{
    return RaAchievement::factory()->create(array_replace([
        'ra_game_id' => test()->set->id,
        'points' => $points,
    ], $state));
}

it('counts only the achievements that count', function () {
    $core = achievementWorth(10);
    $unofficial = achievementWorth(50, ['core' => false]);
    $removed = achievementWorth(25, ['removed_at' => now()]);
    achievementWorth(5);

    foreach ([$core, $unofficial, $removed] as $achievement) {
        RaUnlock::factory()->forAchievement($achievement)->create(['user_id' => $this->user->id]);
    }

    $this->progress->recompute($this->user->id, $this->set->id);

    $row = RaProgress::firstWhere('user_id', $this->user->id);

    // Three unlocked, but only the core one that is still in the set counts —
    // otherwise 41/40 becomes possible and a past mastery stops adding up.
    expect($row->achievements_possible)->toBe(2)
        ->and($row->points_possible)->toBe(15)
        ->and($row->unlocked_count)->toBe(1)
        ->and($row->points_earned)->toBe(10);
});

it('counts hardcore separately from softcore', function () {
    $a = achievementWorth(10);
    $b = achievementWorth(20);

    RaUnlock::factory()->forAchievement($a)->hardcore()->create(['user_id' => $this->user->id]);
    RaUnlock::factory()->forAchievement($b)->create(['user_id' => $this->user->id]);

    $this->progress->recompute($this->user->id, $this->set->id);

    $row = RaProgress::firstWhere('user_id', $this->user->id);

    // A game can be 2/2 softcore and 1/2 hardcore at once, which is the whole
    // reason both are stored rather than one flag.
    expect($row->unlocked_count)->toBe(2)
        ->and($row->unlocked_hardcore_count)->toBe(1)
        ->and($row->points_earned)->toBe(30)
        ->and($row->points_hardcore_earned)->toBe(10);
});

it('keeps the earliest date it has ever seen', function () {
    $achievement = achievementWorth(10);

    $this->progress->writeUnlocks($this->user->id, [[
        'achievement_id' => $achievement->id, 'game_id' => $this->set->id,
        'unlocked_at' => '2026-03-10 12:00:00', 'unlocked_hardcore_at' => null,
    ]]);

    // Earlier wins: RetroAchievements reports the same unlock differently
    // across endpoints, and the first time it happened is the true answer.
    $this->progress->writeUnlocks($this->user->id, [[
        'achievement_id' => $achievement->id, 'game_id' => $this->set->id,
        'unlocked_at' => '2026-03-01 09:00:00', 'unlocked_hardcore_at' => null,
    ]]);

    expect(RaUnlock::first()->unlocked_at->toDateTimeString())->toBe('2026-03-01 09:00:00');

    // Later never wins.
    $this->progress->writeUnlocks($this->user->id, [[
        'achievement_id' => $achievement->id, 'game_id' => $this->set->id,
        'unlocked_at' => '2026-04-01 09:00:00', 'unlocked_hardcore_at' => null,
    ]]);

    expect(RaUnlock::first()->unlocked_at->toDateTimeString())->toBe('2026-03-01 09:00:00');
});

it('does not wipe a stored date when the new payload has none', function () {
    $achievement = achievementWorth(10);

    $this->progress->writeUnlocks($this->user->id, [[
        'achievement_id' => $achievement->id, 'game_id' => $this->set->id,
        'unlocked_at' => '2026-03-10 12:00:00', 'unlocked_hardcore_at' => '2026-03-10 12:00:00',
    ]]);

    // The naive least(coalesce(old, new), new) returns NULL here, because
    // LEAST of anything and NULL is NULL — which would delete the hardcore
    // date every time a softcore-only answer came back.
    $this->progress->writeUnlocks($this->user->id, [[
        'achievement_id' => $achievement->id, 'game_id' => $this->set->id,
        'unlocked_at' => '2026-03-10 12:00:00', 'unlocked_hardcore_at' => null,
    ]]);

    expect(RaUnlock::first()->unlocked_hardcore_at?->toDateTimeString())->toBe('2026-03-10 12:00:00');
});

it('refuses to write zeroes for a set it has never downloaded', function () {
    $empty = RaGame::factory()->create();

    $this->progress->recompute($this->user->id, $empty->id);

    $row = RaProgress::firstWhere('ra_game_id', $empty->id);

    // Otherwise an identified game reads "0 / 0 achievements", which looks
    // like a finished answer rather than a missing one.
    expect($row->achievements_possible)->toBe(0)
        ->and($row->stale)->toBeTrue()
        ->and($row->synced_at)->toBeNull();
});

it('rebuilds from scratch to the same numbers', function () {
    $a = achievementWorth(10);
    $b = achievementWorth(20);

    RaUnlock::factory()->forAchievement($a)->hardcore()->create(['user_id' => $this->user->id]);
    RaUnlock::factory()->forAchievement($b)->create(['user_id' => $this->user->id]);

    $this->progress->recompute($this->user->id, $this->set->id);
    $incremental = RaProgress::firstWhere('user_id', $this->user->id)->only([
        'unlocked_count', 'unlocked_hardcore_count', 'achievements_possible',
        'points_earned', 'points_hardcore_earned', 'points_possible',
    ]);

    // Deliberately wrong, as a half-finished job would leave them.
    RaProgress::where('user_id', $this->user->id)->update([
        'unlocked_count' => 99, 'points_earned' => 999, 'points_possible' => 1,
    ]);

    $this->progress->recomputeAll($this->user->id);

    expect(RaProgress::firstWhere('user_id', $this->user->id)->only(array_keys($incremental)))
        ->toBe($incremental);
});

it('turns the recent endpoint into two dates', function () {
    $achievement = achievementWorth(10);

    Http::fake(['*' => Http::response([[
        'AchievementID' => $achievement->id,
        'GameID' => $this->set->id,
        'Date' => '2026-03-10 12:00:00',
        // This endpoint alone reports hardcore as a boolean; everything else
        // sends two dates. One of the two shapes has to give.
        'HardcoreMode' => 1,
    ]], 200)]);

    (new SyncRecentUnlocks($this->user->id))->handle(
        app(RetroAchievementsService::class),
        $this->progress,
    );

    $unlock = RaUnlock::first();

    expect($unlock->unlocked_at?->toDateTimeString())->toBe('2026-03-10 12:00:00')
        ->and($unlock->unlocked_hardcore_at?->toDateTimeString())->toBe('2026-03-10 12:00:00')
        ->and($this->user->refresh()->retroachievements_synced_at)->not->toBeNull();
});

it('fetches the set rather than dropping an unlock it does not recognise', function () {
    Queue::fake();

    Http::fake(['*' => Http::response([[
        'AchievementID' => 987654,
        'GameID' => 4111,
        'Date' => '2026-03-10 12:00:00',
        'HardcoreMode' => 0,
    ]], 200)]);

    (new SyncRecentUnlocks($this->user->id))->handle(
        app(RetroAchievementsService::class),
        $this->progress,
    );

    // Authors add achievements to live sets, so an unknown id means our copy
    // is stale rather than that something is wrong. Dropping it silently would
    // lose the newest unlocks until somebody re-synced by hand.
    expect(RaUnlock::count())->toBe(0);
    Queue::assertPushed(SyncSet::class, fn (SyncSet $job) => $job->raGameId === 4111);
});

it('takes the award and the rank from the provider', function () {
    $achievement = achievementWorth(10);

    Http::fake([
        '*API_GetGameInfoAndUserProgress*' => Http::response([
            'ID' => $this->set->id,
            'HighestAwardKind' => 'beaten-hardcore',
            // ISO 8601 with an offset, which is the shape this endpoint
            // actually sends — unlike the unlock dates beside it. Written
            // through the query builder, nothing casts it on the way past, and
            // MariaDB rejected it outright: every game the person had beaten
            // failed, and the counters went with it.
            'HighestAwardDate' => '2026-03-11T10:00:00+00:00',
            'Achievements' => [
                $achievement->id => [
                    'ID' => $achievement->id,
                    'DateEarned' => '2026-03-10 12:00:00',
                    'DateEarnedHardcore' => '2026-03-10 12:00:00',
                ],
            ],
        ], 200),
        '*API_GetUserGameRankAndScore*' => Http::response([
            ['User' => 'tester', 'UserRank' => 842, 'TotalScore' => 620],
        ], 200),
    ]);

    (new SyncGameProgress($this->user->id, $this->set->id))->handle(
        app(RetroAchievementsService::class),
        $this->progress,
    );

    $row = RaProgress::firstWhere('user_id', $this->user->id);

    // beaten-hardcore, not beaten: collapsing the two would throw away the
    // distinction the rest of this is built to keep.
    expect($row->highest_award_kind)->toBe(AwardKind::BeatenHardcore)
        ->and($row->highest_award_at?->toDateTimeString())->toBe('2026-03-11 10:00:00')
        ->and($row->site_rank)->toBe(842)
        ->and($row->unlocked_hardcore_count)->toBe(1);
});

it('reads an empty rank answer as no rank', function () {
    achievementWorth(10);

    Http::fake([
        '*API_GetGameInfoAndUserProgress*' => Http::response([
            'ID' => $this->set->id, 'Achievements' => [],
        ], 200),
        // Which is what the provider sends for a game they have not played.
        '*API_GetUserGameRankAndScore*' => Http::response([], 200),
    ]);

    (new SyncGameProgress($this->user->id, $this->set->id))->handle(
        app(RetroAchievementsService::class),
        $this->progress,
    );

    expect(RaProgress::firstWhere('user_id', $this->user->id)->site_rank)->toBeNull();
});
