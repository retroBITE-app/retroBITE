<?php

use App\Enums\AchievementKind;
use App\Enums\RetroAchievementsStatus;
use App\Models\AppSetting;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\RaAchievement;
use App\Models\RaGame;
use App\Models\RaProgress;
use App\Models\RaUnlock;
use App\Models\User;
use App\Support\Console;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-ra-pages-'.Str::random(8);
    mkdir($this->root.'/snes', recursive: true);
    config()->set('settings.games_path', $this->root);

    $this->user = User::factory()->create(['retroachievements_username' => 'tester']);
    $this->actingAs($this->user);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
});

function gameWithProgress(int $unlocked, int $possible, array $progress = []): Game
{
    $set = RaGame::factory()->synced()->create();

    $game = Game::factory()->forConsole('snes')->matched()->create([
        'retroachievements_id' => $set->id,
        'retroachievements_status' => RetroAchievementsStatus::Matched,
    ]);

    RaProgress::factory()->create(array_replace([
        'user_id' => test()->user->id,
        'ra_game_id' => $set->id,
        'unlocked_count' => $unlocked,
        'unlocked_hardcore_count' => (int) floor($unlocked / 2),
        'achievements_possible' => $possible,
        'points_earned' => $unlocked * 10,
        'points_possible' => $possible * 10,
    ], $progress));

    return $game;
}

it('carries this person\'s progress onto the shelf, and nothing where there is no set', function () {
    // 31 unlocked, 15 of them hardcore. Both views read the same joined row,
    // so the shelf and the list cannot disagree about what is known.
    $withSet = gameWithProgress(31, 49);

    // Somebody else's progress on the same set is not ours.
    RaProgress::factory()->create([
        'user_id' => User::factory()->create()->id,
        'ra_game_id' => $withSet->retroachievements_id,
        'unlocked_count' => 2,
        'achievements_possible' => 49,
    ]);

    $noSet = Game::factory()->forConsole('snes')->matched()->create(['title' => 'No Set', 'slug' => 'no-set']);

    $games = collect(Livewire::test('games.index')->instance()->games->items())->keyBy('id');

    expect($games)->toHaveCount(2)
        ->and($games[$withSet->id]->ra_unlocked)->toBe(31)
        ->and($games[$withSet->id]->ra_unlocked_hardcore)->toBe(15)
        ->and($games[$withSet->id]->ra_achievements_possible)->toBe(49)
        // A bar at nought would read as a set nobody has started, which is a
        // different thing from a game RetroAchievements has never heard of.
        ->and($games[$noSet->id]->ra_achievements_possible)->toBeNull();
});

it('does not add a query per row in the list view either', function () {
    Game::factory()->count(12)->forConsole('snes')->matched()->create()
        ->each(fn (Game $game) => GameFile::factory()->for($game)->create());

    DB::enableQueryLog();
    Livewire::withQueryParams(['view' => 'table'])->test('games.index')->assertOk();
    $queries = count(DB::getRawQueryLog());
    DB::disableQueryLog();

    // The cover comes off the eager-loaded media relation and the progress off
    // the join, so neither costs a select. The hardcore setting is read once
    // for the table rather than once per row.
    expect($queries)->toBeLessThan(15);
});

it('does not add a query per row', function () {
    // Placeholders, because blockedFromLookup() short-circuits on a matched
    // game and would hide the N+1 this guards.
    Game::factory()->count(12)->forConsole('snes')->create()
        ->each(fn (Game $game) => GameFile::factory()->for($game)->create());

    DB::enableQueryLog();
    Livewire::test('games.index')->assertOk();
    $queries = count(DB::getRawQueryLog());
    DB::disableQueryLog();

    // The page runs a fixed handful. Before identifiable_files_count it ran
    // one more per card, so twelve rows meant twelve extra selects.
    expect($queries)->toBeLessThan(15);
});

/** The titles the achievement panel would list, in a stable order. */
function panelTitles(Testable $component): array
{
    return collect($component->instance()->achievements)->pluck('title')->sort()->values()->all();
}

it('lists the set with this person\'s unlocks and figures on a game page', function () {
    $game = gameWithProgress(1, 2, ['site_rank' => 842]);

    $achievement = RaAchievement::factory()->create([
        'ra_game_id' => $game->retroachievements_id,
        'title' => 'Give Me Liberty',
        'points' => 5,
    ]);
    RaAchievement::factory()->create([
        'ra_game_id' => $game->retroachievements_id,
        'title' => 'Errand Boy',
    ]);

    RaUnlock::factory()->forAchievement($achievement)->create(['user_id' => $this->user->id]);

    $page = Livewire::test('games.show', ['game' => $game])->instance();

    expect(collect($page->achievements)->pluck('unlocked', 'title')->all())
        ->toEqual(['Give Me Liberty' => true, 'Errand Boy' => false])
        ->and($page->achievementStats[0]['value'])->toBe('1 / 2')
        ->and($page->achievementStats[0]['percent'])->toBe(50)
        ->and($page->achievementStats[3]['value'])->toBe('#842');
});

it('filters the achievement list', function () {
    $game = gameWithProgress(1, 2);

    $unlocked = RaAchievement::factory()->create([
        'ra_game_id' => $game->retroachievements_id, 'title' => 'Already Done',
    ]);
    RaAchievement::factory()->create([
        'ra_game_id' => $game->retroachievements_id, 'title' => 'Still Waiting',
    ]);

    RaUnlock::factory()->forAchievement($unlocked)->create(['user_id' => $this->user->id]);

    $component = Livewire::test('games.show', ['game' => $game])->call('filterAchievements', 'locked');

    expect(panelTitles($component))->toBe(['Still Waiting']);
});

it('narrows the list by kind, on its own axis', function () {
    $game = gameWithProgress(1, 4);

    RaAchievement::factory()->create([
        'ra_game_id' => $game->retroachievements_id, 'title' => 'Ordinary Business', 'kind' => null,
    ]);
    $found = RaAchievement::factory()->create([
        'ra_game_id' => $game->retroachievements_id,
        'title' => 'Already Spotted It',
        'kind' => AchievementKind::Missable,
    ]);
    RaAchievement::factory()->create([
        'ra_game_id' => $game->retroachievements_id,
        'title' => 'Blink And Its Gone',
        'kind' => AchievementKind::Missable,
    ]);
    // The win condition is the last step of beating the game, so Progression
    // counts it rather than listing a path that stops before the end.
    RaAchievement::factory()->create([
        'ra_game_id' => $game->retroachievements_id,
        'title' => 'Beat The Final Boss',
        'kind' => AchievementKind::WinCondition,
    ]);

    RaUnlock::factory()->forAchievement($found)->create(['user_id' => $this->user->id]);

    $component = Livewire::test('games.show', ['game' => $game]);

    expect(collect($component->instance()->achievementKinds)->pluck('count', 'key')->all())
        ->toBe(['missable' => 2, 'progression' => 1]);

    $component->call('toggleKind', 'missable');
    expect(panelTitles($component))->toBe(['Already Spotted It', 'Blink And Its Gone']);

    // The axes are ANDed: what is still there to lose.
    $component->call('filterAchievements', 'locked');
    expect(panelTitles($component))->toBe(['Blink And Its Gone']);

    // And the kind is a toggle, so the state filter survives it going off.
    $component->call('toggleKind', 'missable')->assertSet('achievementKind', '');
    expect(panelTitles($component))->toBe(['Beat The Final Boss', 'Blink And Its Gone', 'Ordinary Business']);

    // Both axes are linkable, and they combine in the URL.
    $linked = Livewire::withQueryParams(['achievements' => 'locked', 'kind' => 'missable'])
        ->test('games.show', ['game' => $game]);

    expect(panelTitles($linked))->toBe(['Blink And Its Gone']);
});

it('offers a kind only to a set that marks one', function () {
    $game = gameWithProgress(0, 1);

    RaAchievement::factory()->create([
        'ra_game_id' => $game->retroachievements_id, 'title' => 'Nothing Special', 'kind' => null,
    ]);

    // No button, and a link into one narrows nothing rather than emptying
    // the panel on a filter the page cannot show as being on.
    $component = Livewire::withQueryParams(['kind' => 'missable'])->test('games.show', ['game' => $game]);

    expect($component->instance()->achievementKinds)->toBe([])
        ->and($component->instance()->activeAchievementKind)->toBe('')
        ->and(panelTitles($component))->toBe(['Nothing Special']);
});

it('totals a console on the console list, hardcore unless told otherwise', function () {
    // The list shows consoles somebody added, not every one with games.
    ConsoleSourceFolder::add(Console::tryFrom('snes'));

    gameWithProgress(31, 49);

    // Hardcore, for the same reason the shelf shows it.
    expect(Livewire::test('consoles.index')->instance()->added->first()['achievements'])
        ->toBe(['unlocked' => 15, 'possible' => 49, 'percent' => 31, 'hardcore' => true]);

    AppSetting::put(AppSetting::RA_HARDCORE_PRIMARY, false);

    expect(Livewire::test('consoles.index')->instance()->added->first()['achievements'])
        ->toBe(['unlocked' => 31, 'possible' => 49, 'percent' => 63, 'hardcore' => false]);
});
