<?php

use App\Enums\UploadRejection;
use App\Exceptions\LibraryPathException;
use App\Exceptions\UploadRejected;
use App\Models\ConsoleSourceFolder;
use App\Models\GameFile;
use App\Models\User;
use App\Services\LibraryScanner;
use App\Services\RomUploads;
use App\Support\Console;
use App\Support\LibraryPath;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * ROM uploads land where the console's layout reads games from, and nowhere
 * else. Everything that decides that is settled when the upload begins; the
 * chunks and the finish only name the upload, so the tests below come at each
 * step with the kind of request a browser could forge.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-upload-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/ps2');
    File::ensureDirectoryExists($this->root.'/snes');
    config()->set('settings.games_path', $this->root);

    $this->user = User::factory()->create();
    $this->ps2 = new Console('ps2');
    $this->snes = new Console('snes');
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function uploads(): RomUploads
{
    return app(RomUploads::class);
}

/**
 * Why the upload was refused, or null if it was not.
 */
function refusal(callable $attempt): ?UploadRejection
{
    try {
        $attempt();
    } catch (UploadRejected $e) {
        return $e->reason;
    }

    return null;
}

function sendChunk(string $id, int $offset, string $bytes): TestResponse
{
    return test()->call('POST', route('uploads.chunk', ['upload' => $id]), [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_X_UPLOAD_OFFSET' => (string) $offset,
    ], $bytes);
}

function stagingFiles(): array
{
    $staging = test()->root.'/'.LibraryPath::STAGING;

    return is_dir($staging) ? File::files($staging) : [];
}

describe('destinations', function () {
    it('offers the OPL game directories', function () {
        ConsoleSourceFolder::add($this->ps2, null, 'opl');

        expect(ConsoleSourceFolder::destinationsFor($this->ps2))->toBe(['DVD' => 'ps2/DVD/', 'CD' => 'ps2/CD/']);
    });

    it('offers only the console root under a custom layout', function () {
        ConsoleSourceFolder::add($this->ps2, null, 'custom');

        expect(ConsoleSourceFolder::destinationsFor($this->ps2))->toBe(['' => 'ps2/']);
    });

    it('falls back to the default layout when the stored one is not offered', function () {
        ConsoleSourceFolder::add($this->snes, null, 'opl');

        expect(ConsoleSourceFolder::destinationsFor($this->snes))->toBe(['' => 'snes/']);
    });

    it('labels the folder the console was pointed at', function () {
        File::ensureDirectoryExists($this->root.'/roms/super');
        ConsoleSourceFolder::add($this->snes, 'roms/super');

        expect(ConsoleSourceFolder::destinationsFor($this->snes))->toBe(['' => 'roms/super/']);
    });
});

describe('begin', function () {
    beforeEach(function () {
        ConsoleSourceFolder::add($this->ps2, null, 'opl');
    });

    it('refuses before anything is staged', function (string $filename, string $destination, UploadRejection $reason) {
        expect(refusal(function () use ($filename, $destination): void {
            uploads()->begin($this->ps2, $this->user->id, $filename, 10, $destination);
        }))->toBe($reason);

        expect(stagingFiles())->toBe([]);
    })->with([
        'a type the console does not play' => ['Game.txt', 'DVD', UploadRejection::WrongType],
        'a name the library ignores' => ['games.bin', 'DVD', UploadRejection::Excluded],
        'a parent segment' => ['../Game.iso', 'DVD', UploadRejection::BadName],
        'a nested path' => ['a/Game.iso', 'DVD', UploadRejection::BadName],
        'a backslash' => ['a\\Game.iso', 'DVD', UploadRejection::BadName],
        'a dot file' => ['.Game.iso', 'DVD', UploadRejection::BadName],
        'a folder the layout does not read' => ['Game.iso', 'ART', UploadRejection::Destination],
        'the root of an OPL drive' => ['Game.iso', '', UploadRejection::Destination],
    ]);

    it('refuses an empty file', function () {
        expect(refusal(function (): void {
            uploads()->begin($this->ps2, $this->user->id, 'Game.iso', 0, 'DVD');
        }))->toBe(UploadRejection::EmptyFile);
    });

    it('refuses a name already in the folder', function () {
        File::ensureDirectoryExists($this->root.'/ps2/DVD');
        File::put($this->root.'/ps2/DVD/Game.iso', 'old');

        expect(refusal(function (): void {
            uploads()->begin($this->ps2, $this->user->id, 'Game.iso', 10, 'DVD');
        }))->toBe(UploadRejection::Exists);

        expect(File::get($this->root.'/ps2/DVD/Game.iso'))->toBe('old');
    });

    it('refuses a console that is not in the library', function () {
        expect(refusal(function (): void {
            uploads()->begin($this->snes, $this->user->id, 'Mario.sfc', 10, '');
        }))->toBe(UploadRejection::Unconfigured);
    });

    it('takes an extension in any case', function () {
        $upload = uploads()->begin($this->ps2, $this->user->id, 'Game.ISO', 10, 'DVD');

        expect($upload->relative())->toBe('DVD/Game.ISO')
            ->and(stagingFiles())->toHaveCount(1);
    });

    it('clears staged files nobody finished', function () {
        $stale = $this->root.'/'.LibraryPath::STAGING.'/'.Str::uuid().'.part';
        File::ensureDirectoryExists(dirname($stale));
        File::put($stale, 'half');
        touch($stale, now()->subDays(2)->getTimestamp());

        uploads()->begin($this->ps2, $this->user->id, 'Game.iso', 10, 'DVD');

        expect(File::exists($stale))->toBeFalse()
            ->and(stagingFiles())->toHaveCount(1);
    });
});

describe('chunks', function () {
    beforeEach(function () {
        ConsoleSourceFolder::add($this->ps2, null, 'opl');
        $this->upload = uploads()->begin($this->ps2, $this->user->id, 'Game.iso', 10, 'DVD');
        $this->actingAs($this->user);
    });

    it('appends chunks in order', function () {
        sendChunk($this->upload->id, 0, 'abcd')->assertOk()->assertJson(['received' => 4]);
        sendChunk($this->upload->id, 4, 'efgh')->assertOk()->assertJson(['received' => 8]);

        expect(File::get($this->root.'/'.LibraryPath::STAGING.'/'.$this->upload->id.'.part'))->toBe('abcdefgh');
    });

    it('answers a chunk out of step with where to carry on', function () {
        sendChunk($this->upload->id, 0, 'abcd')->assertOk();

        sendChunk($this->upload->id, 0, 'abcd')->assertStatus(409)->assertJson(['received' => 4]);
    });

    it('refuses more than the file was declared to hold, and keeps what came before', function () {
        sendChunk($this->upload->id, 0, 'abcd')->assertOk();

        sendChunk($this->upload->id, 4, 'efghijklmn')->assertStatus(422)
            ->assertJson(['message' => UploadRejection::Overflow->label()]);

        expect(File::get($this->root.'/'.LibraryPath::STAGING.'/'.$this->upload->id.'.part'))->toBe('abcd');
    });

    it('answers somebody else\'s upload as a missing one', function () {
        $this->actingAs(User::factory()->create());

        sendChunk($this->upload->id, 0, 'abcd')->assertNotFound();
    });

    it('refuses a missing offset', function () {
        $this->call('POST', route('uploads.chunk', ['upload' => $this->upload->id]), [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ], 'abcd')->assertStatus(422);
    });

    it('needs a login', function () {
        auth()->logout();

        sendChunk($this->upload->id, 0, 'abcd')->assertUnauthorized();
    });
});

describe('finish', function () {
    beforeEach(function () {
        ConsoleSourceFolder::add($this->ps2, null, 'opl');
        $this->upload = uploads()->begin($this->ps2, $this->user->id, 'Grand Theft Auto III (Europe).iso', 10, 'DVD');
        $this->actingAs($this->user);
    });

    it('moves a complete upload into its folder', function () {
        sendChunk($this->upload->id, 0, '0123456789')->assertOk();

        $path = uploads()->finish($this->upload->id, $this->user->id);

        expect($path)->toBe('ps2/DVD/Grand Theft Auto III (Europe).iso')
            ->and(File::get($this->root.'/'.$path))->toBe('0123456789')
            ->and(stagingFiles())->toBe([]);

        // Finished once is finished: the id names nothing any more.
        expect(refusal(function (): void {
            uploads()->finish($this->upload->id, $this->user->id);
        }))->toBe(UploadRejection::Unknown);
    });

    it('refuses an upload that has not all arrived', function () {
        sendChunk($this->upload->id, 0, '01234')->assertOk();

        expect(refusal(function (): void {
            uploads()->finish($this->upload->id, $this->user->id);
        }))->toBe(UploadRejection::Incomplete);

        expect(File::exists($this->root.'/ps2/DVD/Grand Theft Auto III (Europe).iso'))->toBeFalse();
    });

    it('never overwrites a file that appeared while the upload ran', function () {
        sendChunk($this->upload->id, 0, '0123456789')->assertOk();

        File::ensureDirectoryExists($this->root.'/ps2/DVD');
        File::put($this->root.'/ps2/DVD/Grand Theft Auto III (Europe).iso', 'copied over the share');

        expect(refusal(function (): void {
            uploads()->finish($this->upload->id, $this->user->id);
        }))->toBe(UploadRejection::Exists);

        expect(File::get($this->root.'/ps2/DVD/Grand Theft Auto III (Europe).iso'))->toBe('copied over the share')
            ->and(stagingFiles())->toBe([]);
    });

    it('is picked up by the next scan', function () {
        sendChunk($this->upload->id, 0, '0123456789')->assertOk();

        $path = uploads()->finish($this->upload->id, $this->user->id);

        app(LibraryScanner::class)->scan($this->ps2);

        expect(GameFile::query()->where('path', $path)->exists())->toBeTrue();
    });

    it('gives up an upload the browser stopped', function () {
        sendChunk($this->upload->id, 0, '01234')->assertOk();

        uploads()->abandon($this->upload->id, $this->user->id);

        expect(stagingFiles())->toBe([]);

        sendChunk($this->upload->id, 5, '56789')->assertNotFound();
    });
});

describe('the gate', function () {
    it('refuses to move through a symlinked folder', function () {
        ConsoleSourceFolder::add($this->ps2, null, 'opl');

        $elsewhere = $this->root.'-elsewhere';
        File::ensureDirectoryExists($elsewhere);
        symlink($elsewhere, $this->root.'/ps2/DVD');

        $paths = new LibraryPath;
        $staged = $paths->stagingDirectory().'/'.Str::uuid().'.part';
        File::put($staged, 'x');

        try {
            expect(function () use ($paths, $staged): void {
                $paths->moveInto($this->ps2, 'DVD/Game.iso', $staged);
            })->toThrow(LibraryPathException::class);

            expect(File::exists($elsewhere.'/Game.iso'))->toBeFalse();
        } finally {
            File::deleteDirectory($elsewhere);
        }
    });

    it('moves only files out of the staging directory', function () {
        ConsoleSourceFolder::add($this->ps2, null, 'custom');
        File::put($this->root.'/snes/Mario.sfc', 'x');

        expect(function (): void {
            (new LibraryPath)->moveInto($this->ps2, 'Mario.sfc', $this->root.'/snes/Mario.sfc');
        })->toThrow(LibraryPathException::class);

        expect(File::exists($this->root.'/snes/Mario.sfc'))->toBeTrue();
    });
});
