<?php

use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\User;
use App\Support\Console;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Picking games on a console's shelf and carrying them to Conversion.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->root = sys_get_temp_dir().'/retrobite-picks-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/ps2');
    File::ensureDirectoryExists($this->root.'/snes');
    config()->set('settings.games_path', $this->root);

    ConsoleSourceFolder::add(new Console('ps2'));
    ConsoleSourceFolder::add(new Console('snes'));
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('offers Convert games only on the shelf of a console with conversions', function () {
    Livewire::test('games.index', ['console' => 'ps2'])
        ->assertSee('Convert games…')
        ->call('startPicking')
        ->assertSet('picking', true);

    Livewire::test('games.index', ['console' => 'snes'])
        ->assertDontSee('Convert games…')
        ->call('startPicking')
        ->assertSet('picking', false);

    Livewire::test('games.index')->assertDontSee('Convert games…');
});

it('ticks and unticks a game, and refuses another console\'s', function () {
    $mine = Game::factory()->forConsole('ps2')->create();
    $theirs = Game::factory()->forConsole('snes')->create();

    $page = Livewire::test('games.index', ['console' => 'ps2'])
        ->call('togglePick', $mine->id)
        ->assertSet('picks', [])
        ->call('startPicking')
        ->call('togglePick', $mine->id)
        ->call('togglePick', $theirs->id)
        ->assertSet('picks', [$mine->id])
        ->assertSee('Convert 1 game');

    expect(function () use ($page, $theirs): void {
        $page->set('picks', [$theirs->id]);
    })->toThrow(CannotUpdateLockedPropertyException::class);

    $page->call('togglePick', $mine->id)->assertSet('picks', []);
});

it('ticks the whole page, and unticks it again', function () {
    $games = Game::factory()->forConsole('ps2')->count(3)->create();

    $page = Livewire::test('games.index', ['console' => 'ps2'])
        ->call('startPicking')
        ->call('pickPage');

    expect(collect($page->get('picks'))->sort()->values()->all())->toBe($games->pluck('id')->sort()->values()->all());

    $page->call('pickPage')->assertSet('picks', []);
});

it('carries the ticked games to Conversion, on their console', function () {
    [$a, $b] = Game::factory()->forConsole('ps2')->count(2)->create()->sortBy('id')->values()->all();

    Livewire::test('games.index', ['console' => 'ps2'])
        ->call('startPicking')
        ->call('togglePick', $b->id)
        ->call('togglePick', $a->id)
        ->call('convertPicks')
        ->assertRedirect(route('tools.conversion', ['console' => 'ps2', 'search' => $a->id.','.$b->id]));
});

it('goes nowhere with nothing ticked, and lets go of the ticks on cancel', function () {
    $game = Game::factory()->forConsole('ps2')->create();

    Livewire::test('games.index', ['console' => 'ps2'])
        ->call('startPicking')
        ->call('convertPicks')
        ->assertNoRedirect()
        ->call('togglePick', $game->id)
        ->call('stopPicking')
        ->assertSet('picking', false)
        ->assertSet('picks', []);
});
