<?php

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

it('shows progress on the shelf', function () {
    gameWithProgress(31, 49);

    Livewire::test('games.index')->assertSee('31 / 49');
});

it('leads with hardcore when the setting says so', function () {
    gameWithProgress(31, 49);

    AppSetting::put(AppSetting::RA_HARDCORE_PRIMARY, true);

    Livewire::test('games.index')->assertSee('15 / 49')->assertDontSee('31 / 49');
});

it('shows nothing where there is no set', function () {
    Game::factory()->forConsole('snes')->create();

    Livewire::test('games.index')->assertDontSee(' / 0');
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

    $this->get(route('games.show', $game))
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

it('renders the whole way with the network down', function () {
    $game = gameWithProgress(31, 49);

    RaAchievement::factory()->create(['ra_game_id' => $game->retroachievements_id]);

    // Http::preventStrayRequests() is already on for every test, so any call
    // out from a render would fail this rather than quietly succeed. The pages
    // have to be correct from the database alone.
    $this->get(route('games.show', $game))->assertOk()->assertSee('31 / 49');
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

    $this->get(route('consoles.index'))->assertOk()->assertSee('31 / 49');
});
