<?php

use App\Enums\AchievementKind;
use App\Jobs\RetroAchievements\SyncSet;
use App\Models\RaAchievement;
use App\Models\RaGame;
use App\Models\RaProgress;
use App\Services\RetroAchievementsProgress;
use App\Services\RetroAchievementsService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('retroachievements.min_interval', 0);
    config()->set('retroachievements.api_key_fallback', 'test-key');
});

/**
 * @param  array<int, array<string, mixed>>  $achievements
 * @return array<string, mixed>
 */
function raSet(array $achievements, array $game = []): array
{
    return array_replace([
        'ID' => 4111,
        'Title' => 'Super Mario World',
        'ConsoleID' => 3,
        'NumDistinctPlayers' => 1000,
        'NumDistinctPlayersHardcore' => 400,
        'Updated' => '2026-01-01 00:00:00',
        // Keyed by achievement id rather than a list, which is how the
        // provider sends it.
        'Achievements' => collect($achievements)->keyBy('ID')->all(),
    ], $game);
}

/** @return array<string, mixed> */
function raAchievement(int $id, array $overrides = []): array
{
    return array_replace([
        'ID' => $id, 'Title' => "Achievement {$id}", 'Description' => 'Do the thing',
        'Points' => 10, 'TrueRatio' => 12, 'BadgeName' => '12345', 'DisplayOrder' => 0,
        'Flags' => 3, 'NumAwarded' => 100, 'NumAwardedHardcore' => 40, 'type' => null,
    ], $overrides);
}

function runSetSync(int $raGameId = 4111): void
{
    (new SyncSet($raGameId))->handle(
        app(RetroAchievementsService::class),
        app(RetroAchievementsProgress::class),
    );
}

it('stores the set and the numbers the rarity figure needs', function () {
    Http::fake(['*' => Http::response(raSet([
        raAchievement(1, ['type' => 'progression']),
        raAchievement(2, ['Points' => 25]),
    ]), 200)]);

    runSetSync();

    $set = RaGame::find(4111);

    expect($set?->num_achievements)->toBe(2)
        ->and($set->points_total)->toBe(35)
        // Without the denominator "3.4% of players" cannot be worked out, and
        // it arrives in this same answer.
        ->and($set->num_distinct_players)->toBe(1000)
        ->and(RaAchievement::find(1)?->kind)->toBe(AchievementKind::Progression);
});

it('runs twice without duplicating or changing anything', function () {
    $payload = raSet([raAchievement(1), raAchievement(2)]);

    Http::fake(['*' => Http::sequence()->push($payload, 200)->push($payload, 200)]);

    runSetSync();
    runSetSync();

    expect(RaAchievement::count())->toBe(2)
        ->and(RaAchievement::whereNotNull('removed_at')->count())->toBe(0);
});

it('marks an achievement that left the set instead of deleting it', function () {
    Http::fake(['*' => Http::sequence()
        ->push(raSet([raAchievement(1), raAchievement(2)]), 200)
        ->push(raSet([raAchievement(1)]), 200)]);

    runSetSync();
    runSetSync();

    // Somebody may have unlocked it, and the unlock row points here. The row
    // is the only record that it was ever earned.
    expect(RaAchievement::count())->toBe(2)
        ->and(RaAchievement::find(2)?->removed_at)->not->toBeNull()
        ->and(RaAchievement::find(1)?->removed_at)->toBeNull();
});

it('stores unofficial achievements without counting them', function () {
    Http::fake(['*' => Http::response(raSet([
        raAchievement(1, ['Points' => 10]),
        raAchievement(2, ['Points' => 50, 'Flags' => 5]),
    ]), 200)]);

    runSetSync();

    expect(RaAchievement::count())->toBe(2)
        ->and(RaAchievement::find(2)?->core)->toBeFalse()
        // RetroAchievements does not count demoted achievements towards a
        // score either, so counting them here would make our total disagree
        // with theirs on the same page.
        ->and(RaGame::find(4111)?->points_total)->toBe(10)
        ->and(RaGame::find(4111)?->num_achievements)->toBe(1);
});

it('marks existing progress stale when the set changes', function () {
    $set = RaGame::factory()->create(['id' => 4111]);
    $progress = RaProgress::factory()->create(['ra_game_id' => $set->id, 'stale' => false]);

    Http::fake(['*' => Http::response(raSet([raAchievement(1)]), 200)]);

    runSetSync();

    // points_possible on every existing row is wrong the moment the set
    // changes, and only the progress job may put it right.
    expect($progress->refresh()->stale)->toBeTrue();
});
