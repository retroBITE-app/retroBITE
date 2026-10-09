<?php

use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\User;
use App\Services\GlobalSearch;
use App\Support\Console;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The Ctrl+K box: games first and few, then the library's consoles, the
 * pages and the settings tabs, all from one term.
 */

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * @return array<string, list<string>> each group's labels, keyed and ordered as shown
 */
function searchFor(string $term): array
{
    return collect(app(GlobalSearch::class)->results($term))
        ->mapWithKeys(function (array $group): array {
            return [$group['key'] => array_column($group['items'], 'label')];
        })
        ->all();
}

it('puts games first, titles starting with the term ahead of those holding it', function () {
    Game::factory()->forConsole('snes')->create(['title' => 'Super Mario World']);
    Game::factory()->forConsole('snes')->create(['title' => 'Mario Kart']);
    ConsoleSourceFolder::add(new Console('snes'));

    $results = searchFor('mario');

    expect(array_key_first($results))->toBe('games')
        ->and($results['games'])->toBe(['Mario Kart', 'Super Mario World']);
});

it('shows a handful of games and a way to the rest', function () {
    foreach (range(1, 8) as $n) {
        Game::factory()->forConsole('snes')->create(['title' => "Zelda {$n}"]);
    }

    $games = collect(app(GlobalSearch::class)->results('zelda'))->firstWhere('key', 'games')['items'];

    expect($games)->toHaveCount(GlobalSearch::GAME_LIMIT + 1)
        ->and(end($games)['url'])->toBe(route('games.index', ['q' => 'zelda']));
});

it('links a game to its own page', function () {
    $game = Game::factory()->forConsole('snes')->create(['title' => 'Chrono Trigger']);

    $item = collect(app(GlobalSearch::class)->results('chrono'))->firstWhere('key', 'games')['items'][0];

    expect($item['url'])->toBe(route('games.show', $game->routeParameters()))
        ->and($item['detail'])->toBe((new Console('snes'))->name);
});

it('takes % and _ in a term literally', function () {
    Game::factory()->forConsole('snes')->create(['title' => 'Anything At All']);
    Game::factory()->forConsole('snes')->create(['title' => '100% Orange Juice']);

    expect(searchFor('%')['games'] ?? [])->toBe([])
        ->and(searchFor('0%')['games'])->toBe(['100% Orange Juice'])
        ->and(searchFor('a_y')['games'] ?? [])->toBe([]);
});

it('asks for no games on one letter, but still finds pages and settings', function () {
    Game::factory()->forConsole('snes')->create(['title' => 'Doom']);

    $results = searchFor('d');

    expect($results)->not->toHaveKey('games')
        ->and($results['pages'])->toContain('Dashboard')
        ->and($results['settings'])->toContain('Destinations');
});

it('finds a library console by its brand, and only the library\'s', function () {
    ConsoleSourceFolder::add(new Console('megadrive'));

    $results = searchFor(mb_strtolower((new Console('megadrive'))->brand));

    expect($results['consoles'])->toBe([(new Console('megadrive'))->name])
        ->and(collect(app(GlobalSearch::class)->results('mega'))->firstWhere('key', 'consoles')['items'][0]['url'])
        ->toBe(route('consoles.games', ['console' => 'megadrive']));
});

it('finds settings by the other words people use for them', function (string $term, string $tab) {
    expect(searchFor($term)['settings'])->toContain($tab);
})->with([
    ['retroachievements', 'Achievements'],
    ['prune', 'Library'],
    ['screenscraper', 'Scraping'],
    ['smb', 'Destinations'],
]);

it('offers every page and settings tab before anything is typed, without asking for games', function () {
    DB::enableQueryLog();

    $results = searchFor('');

    $gameQueries = collect(DB::getQueryLog())->filter(function (array $query): bool {
        return str_contains($query['query'], 'from `games`');
    });

    expect(array_keys($results))->toBe(['pages', 'settings'])
        ->and($results['settings'])->toHaveCount(9)
        ->and($gameQueries)->toBeEmpty();
});

it('renders the box with what it found', function () {
    Game::factory()->forConsole('snes')->create(['title' => 'EarthBound']);

    Livewire::test('global-search')
        ->set('term', 'earth')
        ->assertSeeText('EarthBound')
        ->call('clear')
        ->assertSet('term', '')
        ->assertSeeText('Dashboard');
});

it('says so when nothing matches', function () {
    Livewire::test('global-search')
        ->set('term', 'qqqqzzzz')
        ->assertSeeText('Nothing matches');
});

it('is on every page behind the sidebar, empty until it is first opened', function () {
    $this->get(route('dashboard'))->assertOk()
        ->assertSee('x-data="globalSearch"', false)
        ->assertDontSee('data-search-item', false);
});

it('lists the quick-jump rows once opened', function () {
    Livewire::test('global-search')
        ->assertDontSeeText('Dashboard')
        ->call('load')
        ->assertSeeText('Dashboard');
});
