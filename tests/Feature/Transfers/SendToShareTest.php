<?php

use App\Enums\FileRole;
use App\Enums\TransferFailure;
use App\Jobs\FileTransferJob;
use App\Jobs\WriteTransferGamelist;
use App\Models\ConsoleSourceFolder;
use App\Models\Destination;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Models\Transfer;
use App\Models\User;
use App\Support\Console;
use App\Transfers\Smb\ShareClient;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Fakes\FolderShareClient;

/*
 * Sending a game to a network share from the game page: the Batocera plan,
 * copied by FileTransferJob, the game list merged after.
 */

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-send-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/games/snes');
    File::ensureDirectoryExists($this->root.'/network/batocera/share');
    config()->set('settings.games_path', $this->root.'/games');

    // The long connection, run inline, so the chain runs as the worker would.
    config()->set('queue.connections.database-long', ['driver' => 'sync']);

    $this->shares = new FolderShareClient($this->root.'/network');
    app()->instance(ShareClient::class, $this->shares);

    ConsoleSourceFolder::add(new Console('snes'));

    $this->game = Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);
    File::put($this->root.'/games/snes/smw.sfc', 'rom-bytes');
    GameFile::factory()->for($this->game)->create([
        'path' => 'snes/smw.sfc', 'filename' => 'smw.sfc', 'extension' => 'sfc', 'role' => FileRole::Rom, 'size_bytes' => 9,
    ]);

    $this->destination = Destination::query()->create(['name' => 'Living room', 'host' => 'batocera', 'share' => 'share']);

    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function shareFile(string $path): string
{
    return test()->root.'/network/batocera/share/'.$path;
}

it('copies the game and its cover to the share, and adds it to the game list', function () {
    Storage::fake('media');
    Storage::disk('media')->put('snes/smw/box-2d/abc.png', 'png-bytes');
    Media::factory()->for($this->game)->ofType('box-2D', 'us')->create(['path' => 'snes/smw/box-2d/abc.png', 'extension' => 'png', 'size_bytes' => 9]);

    Livewire::test('games.show', ['game' => $this->game])
        ->call('sendToShare', $this->destination->id, 'batocera');

    expect(File::get(shareFile('roms/snes/smw.sfc')))->toBe('rom-bytes')
        ->and(File::get(shareFile('roms/snes/images/smw-thumb.png')))->toBe('png-bytes')
        ->and(File::get(shareFile('roms/snes/gamelist.xml')))->toContain('<path>./smw.sfc</path>')
        ->and(Transfer::query()->sole())
        ->status->toBe(Transfer::DONE)
        ->files_done->toBe(2)
        ->files_total->toBe(2);
});

it('keeps everyone else\'s entries in a game list already on the share', function () {
    File::ensureDirectoryExists(shareFile('roms/snes'));
    File::put(shareFile('roms/snes/gamelist.xml'), '<?xml version="1.0"?><gameList><game><path>./zelda.sfc</path><playcount>7</playcount></game></gameList>');

    Livewire::test('games.show', ['game' => $this->game])->call('sendToShare', $this->destination->id, 'batocera');

    $xml = simplexml_load_string(File::get(shareFile('roms/snes/gamelist.xml')));

    expect(array_map('strval', iterator_to_array($xml->xpath('/gameList/game/path'))))->toBe(['./zelda.sfc', './smw.sfc'])
        ->and((string) $xml->game[0]->playcount)->toBe('7');
});

it('says so, and leaves the list alone, when the share already has a different copy', function () {
    File::ensureDirectoryExists(shareFile('roms/snes'));
    File::put(shareFile('roms/snes/smw.sfc'), 'a hack of it');

    Livewire::test('games.show', ['game' => $this->game])
        ->call('sendToShare', $this->destination->id, 'batocera')
        ->call('checkTransfer');

    expect(Transfer::query()->sole())
        ->status->toBe(Transfer::FAILED)
        ->failure->toBe(TransferFailure::Exists)
        ->and(File::get(shareFile('roms/snes/smw.sfc')))->toBe('a hack of it')
        ->and(File::exists(shareFile('roms/snes/gamelist.xml')))->toBeFalse();
});

it('queues the copy and then the list, on the transfer queue', function () {
    Bus::fake();

    Livewire::test('games.show', ['game' => $this->game])
        ->call('sendToShare', $this->destination->id, 'batocera')
        ->assertSet('watchingTransfer', Transfer::query()->sole()->id)
        ->assertSee('Waiting to send to Living room');

    Bus::assertChained([FileTransferJob::class, WriteTransferGamelist::class]);
});

it('follows a transfer that is still running when the page opens', function () {
    $transfer = Transfer::query()->create(['game_id' => $this->game->id, 'destination_id' => $this->destination->id, 'target' => 'batocera', 'status' => Transfer::RUNNING, 'files_total' => 3, 'files_done' => 1]);

    Livewire::test('games.show', ['game' => $this->game])
        ->assertSet('watchingTransfer', $transfer->id)
        ->assertSee('Sending to Living room')
        ->assertSee('1 / 3');
});

it('says when nothing has picked a queued transfer up', function () {
    Transfer::query()->create(['game_id' => $this->game->id, 'destination_id' => $this->destination->id, 'target' => 'batocera', 'status' => Transfer::QUEUED, 'files_total' => 1]);

    Livewire::test('games.show', ['game' => $this->game])->assertDontSee('Nothing has picked this up yet');

    $this->travel(31)->seconds();

    Livewire::test('games.show', ['game' => $this->game])->assertSee('Nothing has picked this up yet');
});

it('offers the saved shares and the USB drive in the modal', function () {
    Livewire::test('games.show', ['game' => $this->game])
        ->assertSee('Send to…')
        ->assertSee('USB drive on this computer')
        ->assertSee('Living room');
});
