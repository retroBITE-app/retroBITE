<?php

use App\Conversion\ConversionQueue;
use App\Conversion\ConversionRunner;
use App\Conversion\Converters;
use App\Conversion\SourceSet;
use App\Conversion\Tools;
use App\Decryption\DiscKeys;
use App\Decryption\Ps3Disc;
use App\Enums\ConversionFailure;
use App\Enums\ConversionStatus;
use App\Exceptions\ConversionFailed;
use App\Exceptions\LibraryPathException;
use App\Jobs\InspectGameFile;
use App\Jobs\RunConversion;
use App\Models\ConsoleSourceFolder;
use App\Models\Conversion;
use App\Models\Game;
use App\Models\GameFile;
use App\Support\Console;
use App\Support\LibraryPath;
use Illuminate\Process\FakeProcessDescription;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\Ps3Image;

/**
 * PS3 decryption, end to end: reading a Redump image, keeping its key beside
 * it, the toolbox's inspection, the gate, and the conversion that swaps the
 * decrypted image in for the encrypted one — with ps3dec faked, and once for
 * real where it is installed.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-ps3-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/ps3');
    config()->set('settings.games_path', $this->root);

    ConsoleSourceFolder::add(new Console('ps3'));

    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/** A PS3 game of one image on disk and on record, with its key beside it unless told otherwise. */
function ps3Game(string $name = 'Game (USA).iso', ?bool $encrypted = true, bool $key = true): GameFile
{
    return Ps3Image::fileRow(test()->root, $name, $encrypted, $key, ['md5' => str_repeat('a', 32)], meta: ['license_id' => 'BLUS30538']);
}

/** ps3dec as it behaves: writes `-o`/`-n`.iso, decrypted unless told the key was wrong. */
function fakePs3dec(bool $wrongKey = false): void
{
    Process::fake(function (PendingProcess $process) use ($wrongKey): FakeProcessDescription {
        $command = array_values((array) $process->command);
        $directory = (string) Arr::get($command, (int) array_search('-o', $command, true) + 1);
        $name = (string) Arr::get($command, (int) array_search('-n', $command, true) + 1);

        Ps3Image::write($directory.'/'.$name.'.iso', $wrongKey ? Ps3Image::KEY : null);

        return Process::describe()->exitCode(0);
    });
}

/** Queue a file's decryption and run it here, as the worker would. */
function decryptPs3(GameFile $file): Conversion
{
    $conversion = app(ConversionQueue::class)->add(SourceSet::fromFile($file->load('game')), 'ps3-decrypt', []);
    (new RunConversion($conversion->id))->handle(app(ConversionRunner::class));

    return $conversion->fresh();
}

it('reads the title ID and whether the image is still encrypted', function () {
    $encrypted = Ps3Disc::open(Ps3Image::write($this->root.'/a.iso'));
    $decrypted = Ps3Disc::open(Ps3Image::write($this->root.'/b.iso', null));

    expect($encrypted?->titleId())->toBe('BLUS30538')
        ->and($encrypted?->encryptedRanges())->toBe([['start' => 32, 'end' => 34]])
        ->and($encrypted?->encrypted())->toBeTrue()
        ->and($decrypted?->titleId())->toBe('BLUS30538')
        ->and($decrypted?->encrypted())->toBeFalse();
});

it('proves a key against the disc, and refuses one that does not fit', function () {
    $disc = Ps3Disc::open(Ps3Image::write($this->root.'/a.iso'));

    expect($disc?->keyMatches(Ps3Image::KEY))->toBeTrue()
        ->and($disc?->keyMatches(Str::lower(Ps3Image::KEY)))->toBeTrue()
        ->and($disc?->keyMatches(str_repeat('0', 32)))->toBeFalse()
        ->and($disc?->keyMatches('not a key'))->toBeFalse();
});

it('will not read a PARAM.SFO that claims to be enormous', function () {
    $path = Ps3Image::write($this->root.'/huge.iso', null);
    $image = (string) file_get_contents($path);
    // PARAM.SFO's size, 10 bytes into its directory record (the name starts at 33).
    $record = (int) strpos($image, 'PARAM.SFO;1') - 33;
    file_put_contents($path, substr_replace($image, pack('V', 0xFFFFFFFF), $record + 10, 4));

    expect(Ps3Disc::open($path)?->titleId())->toBeNull();
});

it('stops at a directory record that runs past the end of the directory', function () {
    $path = Ps3Image::write($this->root.'/damaged.iso', null);
    $image = (string) file_get_contents($path);
    $record = (int) strpos($image, 'PARAM.SFO;1') - 33;
    $sector = $record - $record % 2048;
    // PARAM.SFO renamed and stretched to end 8 bytes short of the sector, where
    // a record begins that has no room for its name.
    $image = substr_replace($image, 'PARAM.SFX;1', $record + 33, 11);
    $image = substr_replace($image, chr($sector + 2040 - $record), $record, 1);
    $image = substr_replace($image, chr(0x40), $sector + 2040, 1);
    file_put_contents($path, $image);

    expect(Ps3Disc::open($path)?->titleId())->toBeNull();
});

it('cannot tell anything from what is not a PS3 disc', function () {
    File::put($this->root.'/noise.iso', random_bytes(64 * 2048));
    $noEboot = Ps3Disc::open(Ps3Image::write($this->root.'/c.iso', eboot: false));

    expect(Ps3Disc::open($this->root.'/noise.iso')?->encrypted())->toBeNull()
        ->and(Ps3Disc::open($this->root.'/noise.iso')?->titleId())->toBeNull()
        ->and($noEboot?->encrypted())->toBeNull()
        ->and(Ps3Disc::open($this->root.'/missing.iso'))->toBeNull();
});

it('reads a key as hex text or raw bytes, .dkey before .key', function () {
    $image = Ps3Image::write($this->root.'/ps3/Game.iso');
    $keys = app(DiscKeys::class);

    expect(DiscKeys::normalise(" 00112233 44556677\n8899aabbccddeeff\r\n"))->toBe(Ps3Image::KEY)
        ->and(DiscKeys::normalise((string) hex2bin(Ps3Image::KEY)))->toBe(Ps3Image::KEY)
        ->and(DiscKeys::normalise('xyz'))->toBeNull()
        ->and($keys->read($image))->toBeNull();

    File::put($this->root.'/ps3/Game.key', str_repeat('1', 32));
    expect($keys->read($image))->toBe(str_repeat('1', 32));

    File::put($this->root.'/ps3/Game.dkey', Ps3Image::KEY);
    expect($keys->read($image))->toBe(Ps3Image::KEY)
        ->and($keys->keyFiles($image))->toBe([$this->root.'/ps3/Game.dkey', $this->root.'/ps3/Game.key']);
});

it('saves a key beside its image as a .dkey', function () {
    $file = ps3Game(key: false);

    app(DiscKeys::class)->save(new Console('ps3'), $file, Str::lower(Ps3Image::KEY));

    expect(File::get($this->root.'/ps3/Game (USA).dkey'))->toBe(Ps3Image::KEY."\n")
        ->and($file->fresh()?->meta?->disc_key)->toBe(Ps3Image::KEY);
});

it('has the PS3 toolbox record the title ID and the encryption', function () {
    $file = ps3Game();
    $file->rememberMeta(['license_id' => null, 'encrypted' => null]);

    (new InspectGameFile($file->id))->handle();

    expect($file->fresh()?->meta?->license_id)->toBe('BLUS30538')
        ->and($file->fresh()?->meta?->encrypted)->toBeTrue()
        ->and($file->fresh()?->meta?->disc_key)->toBe(Ps3Image::KEY);
});

it('leaves a key beside the image off the record when it does not fit the disc', function () {
    $file = ps3Game(key: false);
    $file->rememberMeta(['license_id' => null, 'encrypted' => null]);
    File::put($this->root.'/ps3/Game (USA).dkey', str_repeat('0', 32));

    (new InspectGameFile($file->id))->handle();

    expect($file->fresh()?->meta?->encrypted)->toBeTrue()
        ->and($file->fresh()?->meta?->disc_key)->toBeNull();
});

it('records a decrypted image as not encrypted, false kept rather than dropped', function () {
    $file = ps3Game(encrypted: false, key: false);
    $file->rememberMeta(['license_id' => null, 'encrypted' => null]);

    (new InspectGameFile($file->id))->handle();

    expect($file->fresh()?->meta?->encrypted)->toBeFalse();
});

it('offers decryption only for an encrypted image with its key, and never in the Conversion picker', function () {
    config()->set('decrypters.tools.ps3dec.path', '/bin/true');

    $ready = SourceSet::fromFile(ps3Game('Ready.iso')->load('game'));
    $keyless = SourceSet::fromFile(ps3Game('Keyless.iso', key: false)->load('game'));
    $done = SourceSet::fromFile(ps3Game('Done.iso', encrypted: false)->load('game'));

    $keys = function (SourceSet $set): array {
        return Converters::routesFor($set)->map->key()->all();
    };

    expect($keys($ready))->toBe(['ps3-decrypt'])
        ->and($keys($keyless))->toBe([])
        ->and($keys($done))->toBe([])
        ->and(Converters::pickableFor($ready)->all())->toBe([])
        ->and(Converters::offersOn(new Console('ps3')))->toBeFalse()
        ->and(Converters::onPage(new Console('ps3'), 'decrypt')->map->key()->all())->toBe(['ps3-decrypt'])
        ->and(Converters::onPage(new Console('ps2'), 'decrypt')->all())->toBe([]);
});

it('swaps the decrypted image in under the same name, keeps the row and drops the key', function () {
    config()->set('decrypters.tools.ps3dec.path', '/bin/true');
    fakePs3dec();
    $file = ps3Game();
    $file->rememberMeta(['disc_key' => Ps3Image::KEY]);

    $conversion = decryptPs3($file);

    expect($conversion->status)->toBe(ConversionStatus::Done)
        ->and($conversion->outputs)->toBe(['Game (USA).iso'])
        ->and(Ps3Disc::open($this->root.'/ps3/Game (USA).iso')?->encrypted())->toBeFalse()
        ->and(File::exists($this->root.'/ps3/Game (USA).dkey'))->toBeFalse()
        ->and(File::glob($this->root.'/ps3/.*retrobite-replacing'))->toBe([])
        ->and(GameFile::query()->count())->toBe(1)
        ->and($file->fresh()?->md5)->toBeNull()
        ->and($file->fresh()?->meta?->license_id)->toBeNull()
        ->and($file->fresh()?->meta?->encrypted)->toBeNull()
        ->and($file->fresh()?->meta?->disc_key)->toBe(Ps3Image::KEY)
        ->and($conversion->log)->toContain('Replaced Game (USA).iso')
        ->and($conversion->log)->toContain('Deleted Game (USA).dkey');

    Process::assertRan(function (PendingProcess $process) use ($conversion): bool {
        $command = (array) $process->command;

        return in_array('--skip', $command, true)
            && Arr::get($command, (int) array_search('--dk', $command, true) + 1) === Ps3Image::KEY
            && Str::endsWith((string) $process->path, $conversion->stagingFolder());
    });
});

it('gives ps3dec the key but never writes it into the conversion log', function () {
    config()->set('decrypters.tools.ps3dec.path', '/bin/true');
    fakePs3dec();

    $conversion = decryptPs3(ps3Game());

    Process::assertRan(fn (PendingProcess $process): bool => in_array(Ps3Image::KEY, (array) $process->command, true)
        || in_array(strtolower(Ps3Image::KEY), (array) $process->command, true));
    expect($conversion->status)->toBe(ConversionStatus::Done)
        ->and(Str::lower((string) $conversion->log))->not->toContain(Str::lower(Ps3Image::KEY))
        ->and((string) $conversion->log)->toContain('--dk ********');
});

it('keeps the encrypted image and its key when the output is still encrypted', function () {
    config()->set('decrypters.tools.ps3dec.path', '/bin/true');
    fakePs3dec(wrongKey: true);
    $file = ps3Game();
    $before = md5_file($this->root.'/ps3/Game (USA).iso');

    $conversion = decryptPs3($file);

    expect($conversion->status)->toBe(ConversionStatus::Failed)
        ->and($conversion->failure)->toBe(ConversionFailure::VerifyFailed)
        ->and($conversion->log)->toContain('still reads as encrypted')
        ->and(md5_file($this->root.'/ps3/Game (USA).iso'))->toBe($before)
        ->and(File::exists($this->root.'/ps3/Game (USA).dkey'))->toBeTrue()
        ->and($file->fresh()?->md5)->toBe(str_repeat('a', 32));
});

it('decrypts for real with ps3dec, back to the plain image byte for byte', function () {
    if (! Tools::available('ps3dec')) {
        $this->markTestSkipped('ps3dec is not installed');
    }

    $file = ps3Game();
    $plain = md5_file(Ps3Image::write($this->root.'/plain.iso', null));

    $conversion = decryptPs3($file);

    expect($conversion->status)->toBe(ConversionStatus::Done)
        ->and(md5_file($this->root.'/ps3/Game (USA).iso'))->toBe($plain)
        ->and(File::exists($this->root.'/ps3/Game (USA).dkey'))->toBeFalse();
});

it('knows what decrypts a file: the console\'s converter on Tools → Decrypt that reads its format', function () {
    $iso = ps3Game();
    $pkg = GameFile::factory()->for($iso->game)->create(['path' => 'ps3/Game.pkg', 'filename' => 'Game.pkg', 'extension' => 'pkg']);
    $keys = app(DiscKeys::class);

    expect($keys->decrypterFor(new Console('ps3'), $iso)?->key())->toBe('ps3-decrypt')
        ->and($keys->decrypterFor(new Console('ps3'), $pkg))->toBeNull()
        ->and($keys->decrypterFor(new Console('ps2'), $iso))->toBeNull()
        ->and($keys->fits($iso, Ps3Image::KEY))->toBeTrue()
        ->and($keys->fits($iso, str_repeat('0', 32)))->toBeFalse();
});

it('puts a file in another\'s place in one rename, through the gate only', function () {
    $gate = app(LibraryPath::class);
    $ps3 = new Console('ps3');
    File::put($this->root.'/ps3/old.iso', 'old');
    File::put($this->root.'/ps3/.new', 'new');

    expect($gate->replace($ps3, '.new', 'old.iso'))->toBe('ps3/old.iso')
        ->and(File::get($this->root.'/ps3/old.iso'))->toBe('new')
        ->and(File::exists($this->root.'/ps3/.new'))->toBeFalse()
        ->and(function () use ($gate, $ps3): void {
            $gate->replace($ps3, 'missing.iso', 'old.iso');
        })->toThrow(LibraryPathException::class)
        ->and(function () use ($gate, $ps3): void {
            $gate->replace($ps3, 'old.iso', '../ps2/other.iso');
        })->toThrow(LibraryPathException::class);
});

it('clears a parked image a dead run left behind, rather than refusing every retry', function () {
    config()->set('decrypters.tools.ps3dec.path', '/bin/true');
    fakePs3dec();
    $file = ps3Game();
    File::put($this->root.'/ps3/.Game (USA).iso.retrobite-replacing', 'half a decrypt');

    $conversion = decryptPs3($file);

    expect($conversion->status)->toBe(ConversionStatus::Done)
        ->and(File::glob($this->root.'/ps3/.*retrobite-replacing'))->toBe([])
        ->and(Ps3Disc::open($this->root.'/ps3/Game (USA).iso')?->encrypted())->toBeFalse();
});

it('after a restart, removes a parked image and leaves the encrypted one as it was', function () {
    config()->set('decrypters.tools.ps3dec.path', '/bin/true');
    $file = ps3Game();
    $before = md5_file($this->root.'/ps3/Game (USA).iso');
    $conversion = app(ConversionQueue::class)->add(SourceSet::fromFile($file->load('game')), 'ps3-decrypt', []);
    $conversion->update(['status' => ConversionStatus::Running]);
    Ps3Image::write($this->root.'/ps3/.Game (USA).iso.retrobite-replacing', null);

    app(ConversionRunner::class)->abandon($conversion);

    expect($conversion->fresh()?->status)->toBe(ConversionStatus::Failed)
        ->and($conversion->fresh()?->failure)->toBe(ConversionFailure::Interrupted)
        ->and(File::glob($this->root.'/ps3/.*retrobite-replacing'))->toBe([])
        ->and(md5_file($this->root.'/ps3/Game (USA).iso'))->toBe($before)
        ->and(File::exists($this->root.'/ps3/Game (USA).dkey'))->toBeTrue();
});

it('after a restart, finishes a swap whose rename had already landed', function () {
    config()->set('decrypters.tools.ps3dec.path', '/bin/true');
    $file = ps3Game();
    $conversion = app(ConversionQueue::class)->add(SourceSet::fromFile($file->load('game')), 'ps3-decrypt', []);
    $conversion->update(['status' => ConversionStatus::Swapping]);
    // The rename landed, then the worker died: decrypted image in place, row and key not yet seen to.
    Ps3Image::write($this->root.'/ps3/Game (USA).iso', null);

    app(ConversionRunner::class)->abandon($conversion);

    expect($conversion->fresh()?->status)->toBe(ConversionStatus::Done)
        ->and($conversion->fresh()?->log)->toContain('Finished after a restart')
        ->and(File::exists($this->root.'/ps3/Game (USA).dkey'))->toBeFalse()
        ->and($file->fresh()?->meta?->encrypted)->toBeNull();
});

it('after a restart, renames in a disc still parked when the swap had begun', function () {
    config()->set('decrypters.tools.ps3dec.path', '/bin/true');
    $file = ps3Game();
    $conversion = app(ConversionQueue::class)->add(SourceSet::fromFile($file->load('game')), 'ps3-decrypt', []);
    $conversion->update(['status' => ConversionStatus::Swapping]);
    // Verified and placed, then the worker died before the rename.
    Ps3Image::write($this->root.'/ps3/.Game (USA).iso.retrobite-replacing', null);

    app(ConversionRunner::class)->abandon($conversion);

    expect($conversion->fresh()?->status)->toBe(ConversionStatus::Done)
        ->and(File::glob($this->root.'/ps3/.*retrobite-replacing'))->toBe([])
        ->and(Ps3Disc::open($this->root.'/ps3/Game (USA).iso')?->encrypted())->toBeFalse()
        ->and(File::exists($this->root.'/ps3/Game (USA).dkey'))->toBeFalse();
});

it('after a restart before the swap, never takes a source that reads as decrypted for one that was swapped', function () {
    config()->set('decrypters.tools.ps3dec.path', '/bin/true');
    $file = ps3Game();
    $conversion = app(ConversionQueue::class)->add(SourceSet::fromFile($file->load('game')), 'ps3-decrypt', []);
    $conversion->update(['status' => ConversionStatus::Running]);
    // Decrypted some other way while it waited; nothing of this run was placed.
    Ps3Image::write($this->root.'/ps3/Game (USA).iso', null);

    app(ConversionRunner::class)->abandon($conversion);

    expect($conversion->fresh()?->status)->toBe(ConversionStatus::Failed)
        ->and($conversion->fresh()?->failure)->toBe(ConversionFailure::Interrupted)
        ->and(File::exists($this->root.'/ps3/Game (USA).dkey'))->toBeTrue()
        ->and($file->fresh()?->meta?->license_id)->toBe('BLUS30538');
});

it('cannot be cancelled in the middle of a swap', function () {
    expect(ConversionStatus::Swapping->cancellable())->toBeFalse()
        ->and(ConversionStatus::Swapping->active())->toBeTrue()
        ->and(ConversionStatus::Running->cancellable())->toBeTrue();
});

it('refuses to queue a file another request is queueing at this moment', function () {
    config()->set('decrypters.tools.ps3dec.path', '/bin/true');
    $file = ps3Game();
    // The other tab holds the file's lock between its check and its insert.
    $held = Cache::lock('conversion.file.'.$file->id, 10);
    $held->get();

    expect(fn () => app(ConversionQueue::class)->add(SourceSet::fromFile($file->load('game')), 'ps3-decrypt', []))
        ->toThrow(ConversionFailed::class);

    $held->release();

    expect(app(ConversionQueue::class)->add(SourceSet::fromFile($file->load('game')), 'ps3-decrypt', [])->status)
        ->toBe(ConversionStatus::Queued);
});

it('fails clearly, and leaves the image alone, when its key is gone by the time it runs', function () {
    config()->set('decrypters.tools.ps3dec.path', '/bin/true');
    fakePs3dec();
    $file = ps3Game();
    $before = md5_file($this->root.'/ps3/Game (USA).iso');
    $conversion = app(ConversionQueue::class)->add(SourceSet::fromFile($file->load('game')), 'ps3-decrypt', []);
    File::delete($this->root.'/ps3/Game (USA).dkey');

    (new RunConversion($conversion->id))->handle(app(ConversionRunner::class));

    expect($conversion->fresh()?->status)->toBe(ConversionStatus::Failed)
        ->and($conversion->fresh()?->failure)->toBe(ConversionFailure::Unsupported)
        ->and(md5_file($this->root.'/ps3/Game (USA).iso'))->toBe($before);
    Process::assertNothingRan();
});

it('refuses to queue a file that already has a conversion waiting or running', function () {
    config()->set('decrypters.tools.ps3dec.path', '/bin/true');
    $set = SourceSet::fromFile(ps3Game()->load('game'));
    $queue = app(ConversionQueue::class);

    $queue->add($set, 'ps3-decrypt', []);

    expect(function () use ($queue, $set): void {
        $queue->add($set, 'ps3-decrypt', []);
    })->toThrow(ConversionFailed::class)
        ->and($queue->addMany([$set], 'ps3-decrypt', []))->toMatchArray(['queued' => [], 'skipped' => 1]);
});
