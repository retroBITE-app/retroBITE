<?php

use App\Enums\FileRole;
use App\Jobs\RunConversion;
use App\Models\ConsoleSourceFolder;
use App\Models\Conversion;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\User;
use App\Support\Console;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/**
 * The Conversion tab on a game's page: the Tools → Conversion picker, held to
 * the game's own files, shown only on a console whose config lists
 * converters.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-game-conv-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/bin');
    config()->set('settings.games_path', $this->root);

    foreach (['chdman', 'maxcso', 'ecm', 'unecm', 'extract-xiso', 'cue2pops', 'pops2cue', 'nodtool'] as $tool) {
        File::put($this->root.'/bin/'.$tool, "#!/bin/sh\nexit 0\n");
        chmod($this->root.'/bin/'.$tool, 0755);
        config()->set('converters.tools.'.$tool.'.path', $this->root.'/bin/'.$tool);
    }

    foreach (['ps2', 'snes'] as $console) {
        File::ensureDirectoryExists($this->root.'/'.$console);
        ConsoleSourceFolder::add(new Console($console));
    }

    $this->actingAs(User::factory()->create());
    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/**
 * A game with these files on disk and on record.
 *
 * @param  list<string>  $names
 */
function convertibleGameOn(string $console, array $names): Game
{
    $game = Game::factory()->forConsole($console)->create();

    foreach ($names as $name) {
        File::put(test()->root.'/'.$console.'/'.$name, str_repeat("\0", 4096));

        GameFile::factory()->for($game)->create([
            'path' => $console.'/'.$name,
            'filename' => $name,
            'extension' => Str::lower(pathinfo($name, PATHINFO_EXTENSION)),
            'size_bytes' => 4096,
            'role' => FileRole::Rom,
        ]);
    }

    return $game;
}

function conversionTab(Game $game): TestResponse
{
    return test()->get(route('games.show', [...$game->routeParameters(), 'tab' => 'conversion']));
}

it('has a Conversion tab on a game whose console lists converters', function () {
    $game = convertibleGameOn('ps2', ['Okami.iso']);

    conversionTab($game)
        ->assertOk()
        ->assertSee('Conversion')
        ->assertSee('Okami.iso')
        ->assertSee('Open the queue in Tools → Conversion')
        ->assertSee(route('tools.conversion', ['console' => 'ps2']), false);
});

it('has no Conversion tab where the console\'s config lists no converters', function () {
    $snes = convertibleGameOn('snes', ['Mario.sfc']);

    conversionTab($snes)->assertOk()->assertDontSee('Open the queue in Tools → Conversion');

    config()->set('consoles.ps2.converters', []);

    conversionTab(convertibleGameOn('ps2', ['Okami.iso']))->assertOk()->assertDontSee('Open the queue in Tools → Conversion');
});

it('keeps the tab for a game with nothing to convert, and says so', function () {
    // PS2 plays .nrg, and no PS2 conversion reads it.
    $game = convertibleGameOn('ps2', ['Old.nrg']);

    conversionTab($game)
        ->assertOk()
        ->assertSee('Nothing here any of this console\'s conversions reads.');
});

it('lists only the game\'s own files, a lone one picked already, and queues it', function () {
    $game = convertibleGameOn('ps2', ['Okami.iso']);
    convertibleGameOn('ps2', ['Gran Turismo 4.iso']);

    Livewire::test('conversion.picker', ['consoleKey' => 'ps2', 'gameId' => $game->id])
        ->assertSee('Okami.iso')
        ->assertDontSee('Gran Turismo 4.iso')
        ->assertSet('format', 'chd-dvd')
        ->call('selectFormat', 'zso')
        ->call('add')
        ->assertDispatched('conversion-queued');

    expect(Conversion::query()->sole())
        ->converter->toBe('zso')
        ->game_id->toBe($game->id);

    Queue::assertPushed(RunConversion::class);
});

it('lists every version of a game, and picks one format at a time', function () {
    $game = convertibleGameOn('ps2', ['Okami.iso', 'Okami.cso']);
    $iso = $game->files()->where('extension', 'iso')->sole();
    $cso = $game->files()->where('extension', 'cso')->sole();

    $page = Livewire::test('conversion.picker', ['consoleKey' => 'ps2', 'gameId' => $game->id])
        ->assertSee('Okami.iso')
        ->assertSee('Okami.cso')
        ->assertSet('sources', [])
        ->call('toggleSource', $iso->id)
        ->call('toggleSource', $cso->id);

    expect($page->get('sources'))->toBe([$iso->id]);
});
