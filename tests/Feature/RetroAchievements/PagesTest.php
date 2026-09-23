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

it('shows progress on the shelf, hardcore by default', function () {
    // 31 unlocked, 15 of them hardcore. Hardcore is the figure the site
    // itself leads with, so it is the one the shelf shows unless told not to.
    gameWithProgress(31, 49);

    Livewire::test('games.index')->assertSee('15 / 49')->assertDontSee('31 / 49');
});

it('falls back to softcore when the setting says so', function () {
    gameWithProgress(31, 49);

    AppSetting::put(AppSetting::RA_HARDCORE_PRIMARY, false);

    Livewire::test('games.index')->assertSee('31 / 49')->assertDontSee('15 / 49');
});

it('shows nothing where there is no set', function () {
    Game::factory()->forConsole('snes')->create();

    Livewire::test('games.index')->assertDontSee(' / 0');
});

it('shows progress in the list view as well as on the shelf', function () {
    gameWithProgress(31, 49);

    // The list used to say nothing about achievements at all, so the two views
    // disagreed about what was known. Same figure, same hardcore default.
    Livewire::withQueryParams(['view' => 'table'])->test('games.index')
        ->assertSee('Achievements')
        ->assertSee('15 / 49');
});

it('leaves a list row blank where the game has no set', function () {
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'No Set', 'slug' => 'no-set']);

    // A bar at nought would read as a set nobody has started, which is a
    // different thing from a game RetroAchievements has never heard of.
    Livewire::withQueryParams(['view' => 'table'])->test('games.index')
        ->assertSee('No Set')
        ->assertDontSee(' / 0');
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

it('paginates the shelf in both views', function () {
    Game::factory()->count(30)->forConsole('snes')->create();

    // The cards branch had no paginator at all, so page two of a shelf could
    // only be reached by typing ?page=2 by hand. nextPage is the control Flux
    // renders, so its absence is the bug and its presence is the fix.
    Livewire::withQueryParams(['view' => 'cards'])->test('games.index')->assertSee('nextPage');
    Livewire::withQueryParams(['view' => 'table'])->test('games.index')->assertSee('nextPage');
});

it('shows the achievement panel on a game page', function () {
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

    $this->get(route('games.show', $game->routeParameters()))
        ->assertOk()
        ->assertSee('Give Me Liberty')
        ->assertSee('Errand Boy')
        ->assertSee('1 / 2')
        ->assertSee('#842');
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

    Livewire::test('games.show', ['game' => $game])
        ->call('filterAchievements', 'locked')
        ->assertSee('Still Waiting')
        ->assertDontSee('Already Done');
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

    Livewire::test('games.show', ['game' => $game])
        ->assertSeeInOrder(['Missable', '2', 'Progression', '1'])
        ->call('toggleKind', 'missable')
        ->assertSee('Blink And Its Gone')
        ->assertSee('Already Spotted It')
        ->assertDontSee('Ordinary Business')
        ->assertDontSee('Beat The Final Boss')
        // The axes are ANDed: what is still there to lose.
        ->call('filterAchievements', 'locked')
        ->assertSee('Blink And Its Gone')
        ->assertDontSee('Already Spotted It')
        // And the kind is a toggle, so the state filter survives it going off.
        ->call('toggleKind', 'missable')
        ->assertSet('achievementKind', '')
        ->assertSee('Blink And Its Gone')
        ->assertSee('Ordinary Business')
        ->assertDontSee('Already Spotted It');

    // Both axes are linkable, and they combine in the URL.
    $this->get(route('games.show', $game->routeParameters()).'?achievements=locked&kind=missable')
        ->assertOk()
        ->assertSee('Blink And Its Gone')
        ->assertDontSee('Already Spotted It')
        ->assertDontSee('Ordinary Business');
});

it('offers a kind only to a set that marks one', function () {
    $game = gameWithProgress(0, 1);

    RaAchievement::factory()->create([
        'ra_game_id' => $game->retroachievements_id, 'title' => 'Nothing Special', 'kind' => null,
    ]);

    // No button, and a link into one narrows nothing rather than emptying
    // the panel on a filter the page cannot show as being on.
    $this->get(route('games.show', $game->routeParameters()).'?kind=missable')
        ->assertOk()
        ->assertDontSee('Missable')
        ->assertSee('Nothing Special');
});

it('says which kind of empty the panel is', function () {
    $game = gameWithProgress(0, 1);

    RaAchievement::factory()->create([
        'ra_game_id' => $game->retroachievements_id,
        'title' => 'Blink And Its Gone',
        'kind' => AchievementKind::Missable,
    ]);

    // A filter that matches nothing is not a set that has not arrived yet.
    Livewire::test('games.show', ['game' => $game])
        ->call('toggleKind', 'missable')
        ->call('filterAchievements', 'unlocked')
        ->assertSee('No achievement matches both filters.')
        ->assertDontSee('The set is fetched in the background.');
});

it('renders the whole way with the network down', function () {
    $game = gameWithProgress(31, 49);

    RaAchievement::factory()->create(['ra_game_id' => $game->retroachievements_id]);

    // Http::preventStrayRequests() is already on for every test, so any call
    // out from a render would fail this rather than quietly succeed. The pages
    // have to be correct from the database alone.
    $this->get(route('games.show', $game->routeParameters()))->assertOk()->assertSee('31 / 49');
    $this->get(route('games.index'))->assertOk();
    $this->get(route('dashboard'))->assertOk();
});

it('totals the library on the dashboard', function () {
    gameWithProgress(31, 49);
    gameWithProgress(12, 58);

    $this->get(route('dashboard'))->assertOk()->assertSee('43')->assertSee('107');
});

it('totals a console on the console list', function () {
    // The list shows consoles somebody added, not every one with games.
    ConsoleSourceFolder::add(Console::tryFrom('snes'));

    gameWithProgress(31, 49);

    // Hardcore, for the same reason the shelf shows it.
    $this->get(route('consoles.index'))->assertOk()->assertSee('15 / 49');
});
