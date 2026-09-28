<?php

use App\Enums\TransferFailure;
use App\Enums\TransferMode;
use App\Exceptions\TransferFailed;
use App\Jobs\FileTransferJob;
use App\Models\ConsoleSourceFolder;
use App\Models\Destination;
use App\Models\Game;
use App\Models\Transfer;
use App\Support\Console;
use App\Transfers\FileTransfer;
use App\Transfers\Location;
use App\Transfers\Smb\ShareClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Fakes\FolderShareClient;

/*
 * The one job that moves and copies files. These pin down its promises: it
 * checks before it writes, never writes over anything, and goes all or
 * nothing — whatever the endpoints are.
 */

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-ftj-'.Str::random(8);
    $this->library = $this->root.'/games';
    $this->network = $this->root.'/network';

    File::ensureDirectoryExists($this->library.'/snes');
    File::ensureDirectoryExists($this->network.'/batocera/share');
    File::ensureDirectoryExists($this->network.'/batocera/readonly');
    config()->set('settings.games_path', $this->library);

    ConsoleSourceFolder::add(new Console('snes'));

    $this->shares = new FolderShareClient($this->network);
    app()->instance(ShareClient::class, $this->shares);

    $this->destination = Destination::query()->create(['name' => 'Batocera', 'host' => 'batocera', 'share' => 'share']);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/** A file in the snes folder of the library. */
function romOnDisk(string $relative, string $bytes = 'rom-bytes'): FileTransfer
{
    File::ensureDirectoryExists(dirname(test()->library.'/snes/'.$relative));
    File::put(test()->library.'/snes/'.$relative, $bytes);

    return new FileTransfer(
        Location::library(new Console('snes'), $relative),
        Location::destination(test()->destination, 'roms/snes/'.$relative),
    );
}

function onShare(string $path): string
{
    return test()->network.'/batocera/share/'.$path;
}

function transferRefusal(callable $attempt): ?TransferFailure
{
    try {
        $attempt();
    } catch (TransferFailed $e) {
        return $e->reason;
    }

    return null;
}

/** Every file under a folder, relative to it, hidden ones included. */
function everything(string $folder): array
{
    return collect(File::allFiles($folder, true))
        ->map(fn (SplFileInfo $file): string => Str::after($file->getPathname(), $folder.'/'))
        ->sort()->values()->all();
}

describe('copying to a share', function () {
    it('copies every file, making the folders, and leaves the library as it was', function () {
        FileTransferJob::now([romOnDisk('Mario.sfc'), romOnDisk('Zelda/Zelda.sfc', 'zelda')], TransferMode::Copy);

        expect(File::get(onShare('roms/snes/Mario.sfc')))->toBe('rom-bytes')
            ->and(File::get(onShare('roms/snes/Zelda/Zelda.sfc')))->toBe('zelda')
            ->and(File::get($this->library.'/snes/Mario.sfc'))->toBe('rom-bytes')
            // No probe, no temporary file left behind.
            ->and(everything($this->network.'/batocera/share'))->toBe(['roms/snes/Mario.sfc', 'roms/snes/Zelda/Zelda.sfc']);
    });

    it('skips a file already there at the same size, so a stopped transfer resumes', function () {
        File::ensureDirectoryExists(onShare('roms/snes'));
        File::put(onShare('roms/snes/Mario.sfc'), 'rom-bytes');

        FileTransferJob::now([romOnDisk('Mario.sfc'), romOnDisk('Zelda.sfc')], TransferMode::Copy);

        expect($this->shares->writes)->not->toContain('roms/snes/.Mario.sfc.retrobite-part')
            ->and(File::exists(onShare('roms/snes/Zelda.sfc')))->toBeTrue();
    });

    it('refuses the whole list when a different file has the name, and writes nothing', function () {
        File::ensureDirectoryExists(onShare('roms/snes'));
        File::put(onShare('roms/snes/Zelda.sfc'), 'somebody else\'s zelda');

        expect(transferRefusal(fn () => FileTransferJob::now([romOnDisk('Mario.sfc'), romOnDisk('Zelda.sfc')], TransferMode::Copy)))
            ->toBe(TransferFailure::Exists)
            ->and(File::exists(onShare('roms/snes/Mario.sfc')))->toBeFalse()
            ->and(File::get(onShare('roms/snes/Zelda.sfc')))->toBe('somebody else\'s zelda');
    });

    it('finds out the share cannot be written before it writes anything', function () {
        $this->destination->update(['share' => 'readonly']);

        expect(transferRefusal(fn () => FileTransferJob::now([romOnDisk('Mario.sfc')], TransferMode::Copy)))
            ->toBe(TransferFailure::Unwritable)
            ->and($this->shares->writes)->toBe([]);
    });

    it('refuses a source that is not there', function () {
        $missing = new FileTransfer(Location::library(new Console('snes'), 'Gone.sfc'), Location::destination($this->destination, 'roms/snes/Gone.sfc'));

        expect(transferRefusal(fn () => FileTransferJob::now([romOnDisk('Mario.sfc'), $missing], TransferMode::Copy)))
            ->toBe(TransferFailure::SourceMissing)
            ->and($this->shares->writes)->toBe([]);
    });

    it('refuses the same destination twice in one list', function () {
        $twice = new FileTransfer(Location::library(new Console('snes'), 'Mario.sfc'), Location::destination($this->destination, 'roms/snes/Mario.sfc'));

        expect(transferRefusal(fn () => FileTransferJob::now([romOnDisk('Mario.sfc'), $twice], TransferMode::Copy)))
            ->toBe(TransferFailure::Exists);
    });

    it('says a host that does not answer is worth trying again, and a taken name is not', function () {
        $this->destination->update(['host' => 'offline']);

        $reason = transferRefusal(fn () => FileTransferJob::now([romOnDisk('Mario.sfc')], TransferMode::Copy));

        expect($reason)->toBe(TransferFailure::Unreachable)
            ->and($reason->retryable())->toBeTrue()
            ->and(TransferFailure::Exists->retryable())->toBeFalse();
    });

    it('takes back the copies it made when a later one fails', function () {
        $this->shares->failPutOn = 'Zelda';

        expect(transferRefusal(fn () => FileTransferJob::now([romOnDisk('Mario.sfc'), romOnDisk('Zelda.sfc')], TransferMode::Copy)))
            ->toBe(TransferFailure::Unreachable)
            ->and(everything($this->network.'/batocera/share'))->toBe([]);
    });

    it('never reaches outside the share\'s folder', function () {
        $escape = new FileTransfer(Location::library(new Console('snes'), 'Mario.sfc'), Location::destination($this->destination, '../elsewhere/Mario.sfc'));

        expect(transferRefusal(fn () => FileTransferJob::now([romOnDisk('Mario.sfc'), $escape], TransferMode::Copy)))
            ->toBe(TransferFailure::Rejected);
    });

    it('writes below the destination\'s folder', function () {
        $this->destination->update(['folder' => 'batocera']);

        FileTransferJob::now([romOnDisk('Mario.sfc')], TransferMode::Copy);

        expect(File::exists(onShare('batocera/roms/snes/Mario.sfc')))->toBeTrue();
    });
});

describe('the library', function () {
    it('moves inside a console\'s folder by a rename', function () {
        File::put($this->library.'/snes/Mario.sfc', 'rom-bytes');

        FileTransferJob::now([new FileTransfer(
            Location::library(new Console('snes'), 'Mario.sfc'),
            Location::library(new Console('snes'), 'Platform/Mario.sfc'),
        )], TransferMode::Move);

        expect(File::exists($this->library.'/snes/Mario.sfc'))->toBeFalse()
            ->and(File::get($this->library.'/snes/Platform/Mario.sfc'))->toBe('rom-bytes')
            ->and(everything($this->library.'/snes'))->toBe(['Platform/Mario.sfc']);
    });

    it('refuses to move over a file even at the same size', function () {
        File::put($this->library.'/snes/Mario.sfc', 'rom-bytes');
        File::ensureDirectoryExists($this->library.'/snes/Platform');
        File::put($this->library.'/snes/Platform/Mario.sfc', 'rom-bytes');

        expect(transferRefusal(fn () => FileTransferJob::now([new FileTransfer(
            Location::library(new Console('snes'), 'Mario.sfc'),
            Location::library(new Console('snes'), 'Platform/Mario.sfc'),
        )], TransferMode::Move)))->toBe(TransferFailure::Exists)
            ->and(File::exists($this->library.'/snes/Mario.sfc'))->toBeTrue();
    });

    it('refuses a path outside the console\'s folder', function () {
        File::put($this->library.'/snes/Mario.sfc', 'rom-bytes');

        expect(transferRefusal(fn () => FileTransferJob::now([new FileTransfer(
            Location::library(new Console('snes'), 'Mario.sfc'),
            Location::library(new Console('snes'), '../Mario.sfc'),
        )], TransferMode::Move)))->toBe(TransferFailure::Rejected);
    });

    it('will not write to the artwork disk or into staging', function () {
        File::put($this->library.'/snes/Mario.sfc', 'rom-bytes');

        expect(transferRefusal(fn () => FileTransferJob::now([new FileTransfer(
            Location::library(new Console('snes'), 'Mario.sfc'),
            Location::media('snes/Mario.sfc'),
        )], TransferMode::Copy)))->toBe(TransferFailure::Unwritable);
    });
});

describe('on the queue', function () {
    it('reports to its transfer, and fails it with the reason when it gives up', function () {
        $transfer = Transfer::query()->create(['game_id' => gameForTransfer()->id, 'destination_id' => $this->destination->id, 'target' => 'batocera', 'files_total' => 1]);
        File::ensureDirectoryExists(onShare('roms/snes'));
        File::put(onShare('roms/snes/Mario.sfc'), 'a different mario');

        $job = (new FileTransferJob([romOnDisk('Mario.sfc')], TransferMode::Copy, $transfer->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertFailed();
        expect($transfer->fresh())
            ->status->toBe(Transfer::FAILED)
            ->failure->toBe(TransferFailure::Exists)
            ->finished_at->not->toBeNull();
    });

    it('runs on the long connection, on a queue of its own', function () {
        $job = new FileTransferJob([]);

        expect($job->connection)->toBe('database-long')
            ->and($job->queue)->toBe('transfer');
    });
});

function gameForTransfer(): Game
{
    return Game::factory()->forConsole('snes')->create();
}
