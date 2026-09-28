<?php

use App\Enums\FileRole;
use App\Enums\GameStatus;
use App\Enums\TransferFailure;
use App\Jobs\FileTransferJob;
use App\Jobs\PlanConsoleTransfer;
use App\Models\ConsoleSourceFolder;
use App\Models\Destination;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Transfer;
use App\Models\User;
use App\Support\Console;
use App\Transfers\BatoceraTarget;
use App\Transfers\Endpoints\Endpoints;
use App\Transfers\SendToShare;
use App\Transfers\Smb\ShareClient;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Fakes\FolderShareClient;

/*
 * Sending a whole console at once: every identified game, one version each,
 * one game list written after the last of them.
 */

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-console-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/games/gb');
    File::ensureDirectoryExists($this->root.'/network/batocera/share');
    config()->set('settings.games_path', $this->root.'/games');
    config()->set('queue.connections.database-long', ['driver' => 'sync']);

    app()->instance(ShareClient::class, new FolderShareClient($this->root.'/network'));

    ConsoleSourceFolder::add(new Console('gb'));

    $this->destination = Destination::query()->create(['name' => 'RP4', 'host' => 'batocera', 'share' => 'share']);

    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function gbGame(string $title, array $files, ?GameStatus $status = GameStatus::Matched): Game
{
    $game = Game::factory()->forConsole('gb')->create(['title' => $title, 'slug' => Str::slug($title), 'status' => $status]);

    foreach ($files as $name => $bytes) {
        if ($bytes !== null) {
            File::put(test()->root.'/games/gb/'.$name, $bytes);
        }

        GameFile::factory()->for($game)->create([
            'path' => 'gb/'.$name, 'filename' => $name, 'extension' => 'zip', 'role' => FileRole::Rom,
            'size_bytes' => strlen((string) $bytes),
        ]);
    }

    return $game;
}

function consoleShareFile(string $path): string
{
    return test()->root.'/network/batocera/share/'.$path;
}

it('sends every identified game once, and lists them all in one game list', function () {
    gbGame('Aerostar', ['Aerostar (Japan).zip' => 'jp', 'Aerostar (USA, Europe).zip' => 'eu', 'Aerostar (USA, Europe) (Fr).zip' => 'fr']);
    gbGame('Tetris', ['Tetris (World).zip' => 'tetris']);
    // Neither goes: one has no name to list it under, the other no match.
    gbGame('Unknown', ['unknown.zip' => 'x'], GameStatus::Placeholder);
    gbGame('Homebrew', ['homebrew.zip' => 'y'], GameStatus::Unmatched);

    $sent = app(SendToShare::class)->sendConsole('gb', app(BatoceraTarget::class), $this->destination);

    $gamelist = File::get(consoleShareFile('roms/gb/gamelist.xml'));

    expect($sent['games'])->toBe(2)
        ->and(File::files(consoleShareFile('roms/gb')))->toHaveCount(3)
        ->and(File::exists(consoleShareFile('roms/gb/Aerostar (USA, Europe).zip')))->toBeTrue()
        ->and(File::exists(consoleShareFile('roms/gb/Aerostar (Japan).zip')))->toBeFalse()
        ->and(File::exists(consoleShareFile('roms/gb/unknown.zip')))->toBeFalse()
        ->and(substr_count($gamelist, '<game>'))->toBe(2)
        ->and($gamelist)->toContain('<path>./Aerostar (USA, Europe).zip</path>', '<path>./Tetris (World).zip</path>')
        ->and(Transfer::query()->pluck('status')->unique()->all())->toBe([Transfer::DONE])
        ->and(Transfer::query()->whereNull('batch_id')->count())->toBe(0);
});

it('carries on past a game whose file has gone, and leaves it out of the list', function () {
    gbGame('Tetris', ['Tetris (World).zip' => 'tetris']);
    // A row with nothing on disk behind it: the copy is refused.
    $gone = gbGame('Alleyway', ['Alleyway (World).zip' => null]);

    app(SendToShare::class)->sendConsole('gb', app(BatoceraTarget::class), $this->destination);

    $gamelist = File::get(consoleShareFile('roms/gb/gamelist.xml'));

    expect($gamelist)->toContain('Tetris (World).zip')
        ->not->toContain('Alleyway')
        ->and(Transfer::query()->where('game_id', $gone->id)->sole()->status)->toBe(Transfer::FAILED)
        ->and(Transfer::query()->where('status', Transfer::DONE)->count())->toBe(1);
});

it('merges into the list already on the share, keeping everyone else\'s entries', function () {
    File::ensureDirectoryExists(consoleShareFile('roms/gb'));
    File::put(consoleShareFile('roms/gb/gamelist.xml'), '<?xml version="1.0"?><gameList><game><path>./Mine.zip</path><name>Mine</name></game></gameList>');
    gbGame('Tetris', ['Tetris (World).zip' => 'tetris']);

    app(SendToShare::class)->sendConsole('gb', app(BatoceraTarget::class), $this->destination);

    expect(File::get(consoleShareFile('roms/gb/gamelist.xml')))->toContain('<path>./Mine.zip</path>', '<path>./Tetris (World).zip</path>');
});

it('hands the browser one plan for the console, and merges its list in one go', function () {
    gbGame('Aerostar', ['Aerostar (Japan).zip' => 'jp', 'Aerostar (USA, Europe).zip' => 'eu']);
    gbGame('Tetris', ['Tetris (World).zip' => 'tetris']);

    $plan = $this->getJson(route('transfers.console-plan', ['target' => 'batocera', 'console' => 'gb']))
        ->assertOk()
        ->json();

    expect($plan['games'])->toBe(2)
        ->and(array_column($plan['files'], 'destination'))->toBe(['roms/gb/Aerostar (USA, Europe).zip', 'roms/gb/Tetris (World).zip'])
        ->and($plan['gamelist'])->toBe('roms/gb/gamelist.xml');

    $merged = $this->call('POST', route('transfers.console-gamelist', ['target' => 'batocera', 'console' => 'gb']), [], [], [], ['CONTENT_TYPE' => 'application/xml'], '')
        ->assertOk()
        ->getContent();

    expect(substr_count((string) $merged, '<game>'))->toBe(2);
});

it('refuses a console it does not know', function () {
    $this->getJson(route('transfers.console-plan', ['target' => 'batocera', 'console' => 'nope']))->assertNotFound();
});

it('offers the whole console from its shelf, and builds the plan only once asked', function () {
    gbGame('Tetris', ['Tetris (World).zip' => 'tetris']);

    Livewire::test('games.index', ['console' => 'gb'])->assertSee('Send all games to…');

    Livewire::test('games.send-console', ['console' => 'gb'])
        ->assertDontSee('One copy of each', false)
        ->call('openModal')
        ->assertSet('open', true)
        ->assertSee('The one identified game on Game Boy, one copy of it.');
});

it('starts a share send from the shelf and says how it went', function () {
    gbGame('Tetris', ['Tetris (World).zip' => 'tetris']);
    gbGame('Alleyway', ['Alleyway (World).zip' => null]);

    // Inline, the whole send is over by the time the call returns; the
    // signal that would run checkProgress() is run by hand.
    Livewire::test('games.send-console', ['console' => 'gb'])
        ->call('sendToShare', $this->destination->id, 'batocera')
        ->call('checkProgress')
        ->assertSet('watching', null);

    expect(Transfer::query()->count())->toBe(2)
        ->and(File::exists(consoleShareFile('roms/gb/Tetris (World).zip')))->toBeTrue();
});

it('asks the share what it holds before queuing, and sends again only what is missing', function () {
    gbGame('Tetris', ['Tetris (World).zip' => 'tetris']);
    gbGame('Alleyway', ['Alleyway (World).zip' => 'alleyway']);
    $sender = app(SendToShare::class);
    $target = app(BatoceraTarget::class);

    $sender->sendConsole('gb', $target, $this->destination);

    // One game's file gone from the share since.
    File::delete(consoleShareFile('roms/gb/Alleyway (World).zip'));

    Bus::fake();
    $sent = $sender->sendConsole('gb', $target, $this->destination);
    Bus::assertDispatched(PlanConsoleTransfer::class);

    $ids = Transfer::query()->where('batch_id', $sent['batch'])->pluck('id')->all();
    $sender->planConsole($ids, app(Endpoints::class));

    // One job, for the one game short of a file; the other is done already.
    // Its folders were made by the plan, so the job does not make them again.
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1
        && $batch->jobs->every(fn (FileTransferJob $job): bool => $job->prepared));
    expect(Transfer::query()->where('batch_id', $sent['batch'])->whereColumn('files_skipped', 'files_total')->count())->toBe(1);
});

it('writes the list at once when every game is on the share already', function () {
    gbGame('Tetris', ['Tetris (World).zip' => 'tetris']);
    $sender = app(SendToShare::class);
    $target = app(BatoceraTarget::class);

    $sender->sendConsole('gb', $target, $this->destination);
    File::delete(consoleShareFile('roms/gb/gamelist.xml'));

    $sent = $sender->sendConsole('gb', $target, $this->destination);

    // No copy to wait for, so the list comes straight away and the game is done.
    expect(File::get(consoleShareFile('roms/gb/gamelist.xml')))->toContain('Tetris (World).zip')
        ->and(Transfer::query()->where('batch_id', $sent['batch'])->sole())
        ->status->toBe(Transfer::DONE)
        ->files_skipped->toBe(1);
});

it('makes each folder once in the plan, before any game is copied into it', function () {
    gbGame('Tetris', ['Tetris (World).zip' => 'tetris']);
    Bus::fake();

    $sent = app(SendToShare::class)->sendConsole('gb', app(BatoceraTarget::class), $this->destination);
    app(SendToShare::class)->planConsole(Transfer::query()->where('batch_id', $sent['batch'])->pluck('id')->all(), app(Endpoints::class));

    // Nothing is copied yet — the job is faked — but the folder is there.
    expect(is_dir(consoleShareFile('roms/gb')))->toBeTrue()
        ->and(File::exists(consoleShareFile('roms/gb/Tetris (World).zip')))->toBeFalse();
});

it('stops the whole send in the plan when the share cannot be written, and says why on every game', function () {
    File::ensureDirectoryExists($this->root.'/network/batocera/readonly');
    $this->destination->update(['share' => 'readonly']);
    gbGame('Tetris', ['Tetris (World).zip' => 'tetris']);
    gbGame('Alleyway', ['Alleyway (World).zip' => 'alleyway']);

    app(SendToShare::class)->sendConsole('gb', app(BatoceraTarget::class), $this->destination);

    expect(Transfer::query()->pluck('status')->unique()->all())->toBe([Transfer::FAILED])
        ->and(Transfer::query()->pluck('failure')->unique()->all())->toBe([TransferFailure::Unwritable]);
});
