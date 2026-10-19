<?php

use App\Enums\FileContent;
use App\Jobs\InspectGameFile;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\User;
use App\Support\Console;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Fakes\Ps3Image;

/**
 * Check decryption from a console's shelf and a game's page: what is offered
 * where, and that it reads what it says it does.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-checks-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/ps3');
    File::ensureDirectoryExists($this->root.'/ps2');
    config()->set('settings.games_path', $this->root);

    ConsoleSourceFolder::add(new Console('ps3'));
    ConsoleSourceFolder::add(new Console('ps2'));

    $this->actingAs(User::factory()->create());
    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/**
 * The file ids InspectGameFile was queued for.
 *
 * @return list<int>
 */
function inspected(): array
{
    return collect(Queue::pushed(InspectGameFile::class))
        ->map(function (InspectGameFile $job): int {
            return $job->fileId;
        })
        ->values()
        ->all();
}

it('offers Check decryption only on the shelf of a console something decrypts', function () {
    Ps3Image::fileRow($this->root, 'Locked (USA).iso', key: false);

    Livewire::test('games.index', ['console' => 'ps3'])->assertSee('Check decryption');
    Livewire::test('games.index', ['console' => 'ps2'])->assertDontSee('Check decryption');
    Livewire::test('games.index')->assertDontSee('Check decryption');
});

it('queues a check of every disc image on the shelf, or of one game\'s', function () {
    $one = Ps3Image::fileRow($this->root, 'One (USA).iso', key: false);
    $two = Ps3Image::fileRow($this->root, 'Two (USA).iso');

    Livewire::test('games.index', ['console' => 'ps3'])
        ->call('checkDecryption')
        ->assertDispatched('toast-show');

    expect(inspected())->toEqualCanonicalizing([$one->id, $two->id]);

    // On the scan's queue, so the sidebar shows it under Scanning.
    Queue::assertPushedOn('default', InspectGameFile::class);

    Livewire::test('games.index', ['console' => 'ps3'])->call('checkDecryption', $two->game_id);

    expect(Arr::last(inspected()))->toBe($two->id);
});

it('checks nothing on a shelf nothing decrypts', function () {
    GameFile::factory()->for(Game::factory()->forConsole('ps2')->create())->create(['path' => 'ps2/Game.iso', 'extension' => 'iso']);

    Livewire::test('games.index', ['console' => 'ps2'])->call('checkDecryption');

    Queue::assertNotPushed(InspectGameFile::class);
});

it('checks one disc image from its file card on the game page and says what it found', function () {
    $file = Ps3Image::fileRow($this->root, 'Locked (USA).iso', encrypted: null, key: false);

    Livewire::test('games.show', ['game' => $file->game])
        ->assertSeeHtml('wire:click="checkDecryption('.$file->id.')"')
        ->assertSee('Not checked')
        ->call('checkDecryption', $file->id)
        ->assertDispatched('toast-show', function (string $name, array $params): bool {
            return Arr::get($params, 'dataset.variant') === 'warning';
        })
        ->assertSee('Needs key');

    expect($file->fresh()?->meta?->encrypted)->toBeTrue();
});

it('marks a game with an encrypted image on the shelf, as a card and as a row', function () {
    Ps3Image::fileRow($this->root, 'Locked (USA).iso', key: false);
    Ps3Image::fileRow($this->root, 'Plain (USA).iso', encrypted: false, key: false);

    $page = Livewire::test('games.index', ['console' => 'ps3']);
    $page->assertSee('Encrypted');

    $flags = collect($page->instance()->games->items())->mapWithKeys(function (Game $game): array {
        return [$game->title => (bool) $game->has_encrypted_files];
    })->all();

    expect($flags)->toBe(['Locked' => true, 'Plain' => false]);
});

it('says DLC on the file card and the shelf for an add-on disc, and nothing for a game', function () {
    $addOn = Ps3Image::fileRow($this->root, 'Extra (Europe) (Add-On Disc).iso', key: false, file: ['content_type' => FileContent::Dlc]);
    Ps3Image::fileRow($this->root, 'Plain (USA).iso', encrypted: false, key: false);

    Livewire::test('games.show', ['game' => $addOn->game])
        ->assertSee('DLC')
        ->assertSee('Extra content for the base game');

    $page = Livewire::test('games.index', ['console' => 'ps3']);
    $page->assertSee('DLC');

    $extras = collect($page->instance()->games->items())->mapWithKeys(function (Game $game): array {
        return [$game->title => $game->extra_content];
    })->all();

    expect($extras)->toBe(['Extra (Europe)' => 'dlc', 'Plain' => null]);
});
