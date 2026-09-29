<?php

use App\Conversion\ConversionQueue;
use App\Conversion\ConversionRunner;
use App\Conversion\Converter;
use App\Conversion\SourceSet;
use App\Enums\ConversionFailure;
use App\Enums\ConversionStatus;
use App\Enums\FileRole;
use App\Exceptions\ConversionFailed;
use App\Jobs\RunConversion;
use App\Jobs\ScanConsoleFolder;
use App\Models\ConsoleSourceFolder;
use App\Models\Conversion;
use App\Models\ConversionStat;
use App\Models\Game;
use App\Models\GameFile;
use App\Support\Console;
use App\Support\LibraryPath;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * A conversion's life on the toolbox worker: queued, running, verifying,
 * over — and cancelled, failed, retried, interrupted — with the tools faked
 * so each test decides what they print, what they write and how they exit.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-conv-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/ps2');
    File::ensureDirectoryExists($this->root.'/psx');
    File::ensureDirectoryExists($this->root.'/gc');
    File::ensureDirectoryExists($this->root.'/wii');
    File::ensureDirectoryExists($this->root.'/bin');
    config()->set('settings.games_path', $this->root);

    foreach (['chdman', 'maxcso', 'ecm', 'unecm', 'extract-xiso', 'cue2pops', 'pops2cue', 'nodtool'] as $tool) {
        File::put($this->root.'/bin/'.$tool, "#!/bin/sh\nexit 0\n");
        chmod($this->root.'/bin/'.$tool, 0755);
        config()->set('converters.tools.'.$tool.'.path', $this->root.'/bin/'.$tool);
    }

    ConsoleSourceFolder::add(new Console('ps2'));
    ConsoleSourceFolder::add(new Console('psx'));
    ConsoleSourceFolder::add(new Console('gc'));
    ConsoleSourceFolder::add(new Console('wii'));

    Queue::fake();

    $this->ran = [];
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/**
 * Stand in for every tool: write what the command says it writes, print a
 * chdman-style progress line, and exit as told — cue2pops, as the real one
 * does, with 1 for success. Every command is noted, in order, in
 * test()->ran as "tool verb".
 *
 * @param  array<string, int>  $exits  first argument (createdvd, verify, ...) or tool name => exit code
 * @param  string  $stdout  what every command prints to stdout, for a verifier whose verdict is in it
 */
function fakeTools(array $exits = [], string $stdout = ''): void
{
    Process::fake(function (PendingProcess $process) use ($exits, $stdout) {
        $command = array_values((array) $process->command);
        $tool = basename((string) $command[0]);
        $verb = (string) Arr::get($command, 1, '');
        $written = [];
        test()->ran = [...test()->ran, $tool.' '.($tool === 'chdman' ? $verb : basename($verb))];

        foreach (['-o', '-ob'] as $flag) {
            $at = array_search($flag, $command, true);

            if ($at !== false && ! in_array($verb, ['verify', 'info'], true)) {
                $written[] = $command[$at + 1];
            }
        }

        if (in_array($tool, ['ecm', 'unecm', 'cue2pops'], true) || ($tool === 'nodtool' && $verb === 'convert')) {
            $written[] = end($command);
        }

        // pops2cue writes beside what it was given.
        if ($tool === 'pops2cue') {
            array_push($written, Str::replaceLast('.VCD', '.cue', $verb), Str::replaceLast('.VCD', '.bin', $verb));
        }

        foreach ($written as $path) {
            File::put($path, str_repeat('x', 64));
        }

        return Process::describe()
            ->output($stdout)
            ->errorOutput("Compressing, 50.0% complete... (ratio=70.0%)\r")
            ->exitCode(Arr::get($exits, $verb, Arr::get($exits, $tool, $tool === 'cue2pops' ? 1 : 0)))
            ->runsFor(2);
    });
}

/**
 * A game of one or more files under the console's folder, on disk and on record.
 *
 * @param  array<string, int>  $files  name => size
 */
function convertibleGame(string $console, array $files): Game
{
    $game = Game::factory()->forConsole($console)->create(['title' => 'Game']);
    $sheet = null;

    foreach ($files as $name => $size) {
        $extension = Str::lower(pathinfo($name, PATHINFO_EXTENSION));
        $role = match ($extension) {
            'cue' => FileRole::Sheet,
            'm3u' => FileRole::Playlist,
            default => $sheet !== null && $extension === 'bin' ? FileRole::Track : FileRole::Rom,
        };

        File::ensureDirectoryExists(dirname(test()->root.'/'.$console.'/'.$name));
        File::put(test()->root.'/'.$console.'/'.$name, str_repeat("\0", $size));

        $file = GameFile::factory()->for($game)->create([
            'path' => $console.'/'.$name,
            'filename' => basename($name),
            'extension' => $extension,
            'size_bytes' => $size,
            'role' => $role,
            'parent_id' => $role === FileRole::Track ? $sheet?->id : null,
        ]);

        $sheet = $role === FileRole::Sheet ? $file : $sheet;
    }

    return $game;
}

/** @param  array<string, bool|string>  $options */
function queueConversion(Game $game, string $converter, array $options = []): Conversion
{
    $set = SourceSet::fromFile($game->files()->whereNull('parent_id')->orderBy('id')->firstOrFail());

    return app(ConversionQueue::class)->add($set, $converter, $options);
}

function runConversion(Conversion $conversion): Conversion
{
    (new RunConversion($conversion->id))->handle(app(ConversionRunner::class));

    return $conversion->fresh();
}

function stagingOf(Conversion $conversion): string
{
    return test()->root.'/'.LibraryPath::STAGING.'/'.$conversion->stagingFolder();
}

it('converts a PS2 ISO to CHD, verifies it, and puts it beside the source', function () {
    fakeTools();

    $conversion = runConversion(queueConversion(convertibleGame('ps2', ['DVD/Game.iso' => 4096]), 'chd-dvd', [Converter::VERIFY => true]));

    expect($conversion->status)->toBe(ConversionStatus::Done)
        ->and($conversion->progress)->toBe(100.0)
        ->and($conversion->outputs)->toBe(['Game.chd'])
        ->and($conversion->log)->toContain('$ chdman createdvd -i ps2/DVD/Game.iso')
        ->and($conversion->log)->toContain('Wrote DVD/Game.chd')
        ->and($conversion->log)->not->toContain($this->root)
        ->and(File::exists($this->root.'/ps2/DVD/Game.chd'))->toBeTrue()
        ->and(File::exists($this->root.'/ps2/DVD/Game.iso'))->toBeTrue()
        ->and(File::exists(stagingOf($conversion)))->toBeFalse();

    Process::assertRan(function (PendingProcess $process): bool {
        return Arr::get((array) $process->command, 1) === 'verify';
    });

    Queue::assertPushed(RunConversion::class);
    Queue::assertPushed(ScanConsoleFolder::class);
});

it('keeps only the options the converter understands, keep-source on by default', function () {
    fakeTools();

    $conversion = queueConversion(convertibleGame('ps2', ['Game.iso' => 4096]), 'cso', [Converter::VERIFY => true, 'nonsense' => true]);

    expect($conversion->options)->toBe([
        Converter::COMPRESSION => 'default',
        Converter::KEEP_SOURCE => true,
        Converter::ADVANCED => ['block' => '2048', 'threads' => 'auto'],
    ]);
});

it('passes the advanced settings to the tool, and only values the setting offers', function () {
    fakeTools();
    $game = convertibleGame('ps2', ['Game.iso' => 4096]);

    $conversion = runConversion(queueConversion($game, 'zso', [Converter::ADVANCED => ['block' => '8192', 'threads' => '4']]));

    expect($conversion->log)->toContain('maxcso --format=zso --block=8192 --threads=4');

    // Anything the setting does not list is its default, and so is nothing.
    $forged = queueConversion($game, 'cso', [Converter::ADVANCED => ['block' => '2048; rm -rf /', 'threads' => '64']]);

    expect($forged->options[Converter::ADVANCED])->toBe(['block' => '2048', 'threads' => 'auto']);
});

it('gives chdman its hunk size and processors', function () {
    fakeTools();
    $game = convertibleGame('ps2', ['Game.iso' => 4096]);

    $conversion = runConversion(queueConversion($game, 'chd-dvd'));

    expect($conversion->log)->toContain('createdvd -i ps2/Game.iso -o conv-'.$conversion->id.'/Game.chd -hs 4096')
        ->and($conversion->log)->not->toContain('-np');

    File::delete($this->root.'/ps2/Game.chd');

    $tuned = runConversion(queueConversion($game, 'chd-dvd', [Converter::ADVANCED => ['hunk' => '2048', 'processors' => '2']]));

    expect($tuned->log)->toContain('-hs 2048 -np 2');
});

it('learns how big each kind of conversion comes out, and keeps it past clearing the queue', function () {
    fakeTools();

    runConversion(queueConversion(convertibleGame('ps2', ['Game.iso' => 4096]), 'cso'));

    // The fake writes 64 bytes for a 4,096-byte source.
    expect(Conversion::query()->sole()->only(['source_bytes', 'output_bytes']))->toBe(['source_bytes' => 4096, 'output_bytes' => 64])
        ->and(ConversionStat::ratio('cso', 'ps2'))->toBe(64 / 4096)
        ->and(ConversionStat::ratio('zso', 'ps2'))->toBeNull()
        ->and(ConversionStat::ratio('cso', 'psx'))->toBeNull();

    app(ConversionQueue::class)->clearFinished();

    expect(ConversionStat::ratio('cso', 'ps2'))->toBe(64 / 4096);
});

it('refuses a format the console does not read, from the page and in the job', function () {
    fakeTools();
    $game = convertibleGame('psx', ['Game.iso' => 4096]);

    expect(function () use ($game): void {
        queueConversion($game, 'cso');
    })->toThrow(ConversionFailed::class);

    // A row that got in some other way is refused when it runs.
    $conversion = Conversion::query()->create([
        'console' => 'psx', 'converter' => 'cso', 'game_id' => $game->id,
        'game_file_id' => $game->files()->first()->id, 'label' => 'Game.iso',
        'sources' => ['psx/Game.iso'], 'options' => [],
    ]);

    expect(runConversion($conversion))
        ->status->toBe(ConversionStatus::Failed)
        ->failure->toBe(ConversionFailure::Unsupported);

    Process::assertNothingRan();
});

it('fails with the tool\'s exit, keeps the log, and leaves nothing written', function () {
    fakeTools(['createdvd' => 1]);

    $conversion = runConversion(queueConversion(convertibleGame('ps2', ['Game.iso' => 4096]), 'chd-dvd'));

    expect($conversion->status)->toBe(ConversionStatus::Failed)
        ->and($conversion->failure)->toBe(ConversionFailure::ToolFailed)
        ->and($conversion->log)->toContain('createdvd')
        ->and(File::exists($this->root.'/ps2/Game.chd'))->toBeFalse()
        ->and(File::exists(stagingOf($conversion)))->toBeFalse();

    Queue::assertNotPushed(ScanConsoleFolder::class);
});

it('keeps nothing that did not verify', function () {
    fakeTools(['verify' => 1]);

    $conversion = runConversion(queueConversion(convertibleGame('ps2', ['Game.iso' => 4096]), 'chd-dvd', [Converter::VERIFY => true, Converter::KEEP_SOURCE => false]));

    expect($conversion->failure)->toBe(ConversionFailure::VerifyFailed)
        ->and(File::exists($this->root.'/ps2/Game.chd'))->toBeFalse()
        ->and(File::exists($this->root.'/ps2/Game.iso'))->toBeTrue();
});

it('writes over nothing, and does not start when the output is taken', function () {
    fakeTools();
    $game = convertibleGame('ps2', ['Game.iso' => 4096]);
    File::put($this->root.'/ps2/Game.chd', 'somebody\'s own');

    $conversion = runConversion(queueConversion($game, 'chd-dvd'));

    expect($conversion->failure)->toBe(ConversionFailure::Exists)
        ->and(File::get($this->root.'/ps2/Game.chd'))->toBe('somebody\'s own');

    Process::assertNothingRan();
});

it('deletes the source only once the output is in, when keep-source is off', function () {
    fakeTools();
    $game = convertibleGame('ps2', ['Game.iso' => 4096]);

    $conversion = runConversion(queueConversion($game, 'chd-dvd', [Converter::KEEP_SOURCE => false]));

    expect($conversion->status)->toBe(ConversionStatus::Done)
        ->and(File::exists($this->root.'/ps2/Game.chd'))->toBeTrue()
        ->and(File::exists($this->root.'/ps2/Game.iso'))->toBeFalse()
        ->and($game->files()->count())->toBe(0);
});

it('stops a running conversion that is cancelled, and cleans up after it', function () {
    fakeTools();
    $conversion = queueConversion(convertibleGame('ps2', ['Game.iso' => 4096]), 'chd-dvd');
    $conversion->update(['cancel_requested_at' => now()]);

    $conversion = runConversion($conversion);

    expect($conversion->status)->toBe(ConversionStatus::Cancelled)
        ->and($conversion->failure)->toBeNull()
        ->and(File::exists($this->root.'/ps2/Game.chd'))->toBeFalse()
        ->and(File::exists(stagingOf($conversion)))->toBeFalse();
});

it('cancels a queued conversion at once, and the job then does nothing', function () {
    fakeTools();
    $conversion = queueConversion(convertibleGame('ps2', ['Game.iso' => 4096]), 'chd-dvd');

    app(ConversionQueue::class)->cancel($conversion);

    expect(runConversion($conversion)->status)->toBe(ConversionStatus::Cancelled);

    Process::assertNothingRan();
});

it('retries a failed conversion as it was asked for', function () {
    fakeTools(['createdvd' => 1]);
    $conversion = runConversion(queueConversion(convertibleGame('ps2', ['Game.iso' => 4096]), 'chd-dvd'));

    app(ConversionQueue::class)->retry($conversion);

    expect($conversion->fresh())
        ->status->toBe(ConversionStatus::Queued)
        ->failure->toBeNull()
        ->log->toBeNull();

    Queue::assertPushed(RunConversion::class, 2);

    fakeTools();

    expect(runConversion($conversion)->status)->toBe(ConversionStatus::Done);
});

it('converts every disc of a set in one job and writes a playlist of the new discs', function () {
    fakeTools();
    $game = convertibleGame('psx', [
        'FF9/FF9 (Disc 1).cue' => 80, 'FF9/FF9 (Disc 1).bin' => 2352,
        'FF9/FF9 (Disc 2).cue' => 80, 'FF9/FF9 (Disc 2).bin' => 2352,
        'FF9/FF9.m3u' => 10,
    ]);
    $playlist = $game->files()->where('role', FileRole::Playlist)->firstOrFail();
    $game->files()->where('role', FileRole::Sheet)->update(['parent_id' => $playlist->id]);

    $conversion = runConversion(queueConversion($game, 'chd-cd'));

    // The old playlist stays, so the new one carries the format in its name.
    expect($conversion->status)->toBe(ConversionStatus::Done)
        ->and($conversion->outputs)->toBe(['FF9 (Disc 1).chd', 'FF9 (Disc 2).chd', 'FF9 (CHD).m3u'])
        ->and(File::get($this->root.'/psx/FF9/FF9 (CHD).m3u'))->toBe("FF9 (Disc 1).chd\nFF9 (Disc 2).chd\n")
        ->and(File::exists($this->root.'/psx/FF9/FF9.m3u'))->toBeTrue();
});

it('names the new playlist as the old one was, when the old one goes', function () {
    fakeTools();
    $game = convertibleGame('psx', ['MGS (Disc 1).bin' => 2352, 'MGS (Disc 2).bin' => 2352]);

    $conversion = runConversion(queueConversion($game, 'chd-cd', [Converter::KEEP_SOURCE => false]));

    expect($conversion->outputs)->toBe(['MGS (Disc 1).chd', 'MGS (Disc 2).chd', 'MGS.m3u'])
        ->and(File::get($this->root.'/psx/MGS.m3u'))->toBe("MGS (Disc 1).chd\nMGS (Disc 2).chd\n")
        ->and(File::exists($this->root.'/psx/MGS (Disc 1).bin'))->toBeFalse();
});

it('writes a sheet for a bare image, naming it relative to the sheet', function () {
    fakeTools();

    $conversion = runConversion(queueConversion(convertibleGame('psx', ['Crash/Crash.bin' => 2352 * 4]), 'chd-cd'));

    expect($conversion->status)->toBe(ConversionStatus::Done)
        ->and($conversion->log)->toContain('Crash.source.cue');

    Process::assertRan(function (PendingProcess $process): bool {
        return Arr::get((array) $process->command, 1) === 'createcd'
            && Str::endsWith((string) Arr::get((array) $process->command, 3), 'Crash.source.cue');
    });
});

it('marks a conversion the worker died holding as interrupted', function () {
    fakeTools();
    $conversion = queueConversion(convertibleGame('ps2', ['Game.iso' => 4096]), 'chd-dvd');
    $conversion->update(['status' => ConversionStatus::Running]);
    File::ensureDirectoryExists(stagingOf($conversion));
    File::put(stagingOf($conversion).'/Game.chd', 'half');

    // Handed out again by the queue.
    expect(runConversion($conversion))
        ->status->toBe(ConversionStatus::Failed)
        ->failure->toBe(ConversionFailure::Interrupted)
        ->and(File::exists(stagingOf($conversion)))->toBeFalse();

    Process::assertNothingRan();
});

it('recovers after a restart: running rows fail, queued ones wait, leftovers go', function () {
    fakeTools();
    $game = convertibleGame('ps2', ['Game.iso' => 4096]);
    $running = queueConversion($game, 'chd-dvd');
    $running->update(['status' => ConversionStatus::Verifying]);
    $queued = queueConversion($game, 'cso');
    File::ensureDirectoryExists($this->root.'/'.LibraryPath::STAGING.'/conv-9999');

    $this->artisan('conversion:recover')->assertSuccessful();

    expect($running->fresh()->failure)->toBe(ConversionFailure::Interrupted)
        ->and($queued->fresh()->status)->toBe(ConversionStatus::Queued)
        ->and(File::exists($this->root.'/'.LibraryPath::STAGING.'/conv-9999'))->toBeFalse();
});

it('waits its turn when every slot is taken', function () {
    // Set here rather than read from .env, where it is somebody's to raise.
    config()->set('converters.concurrency', 1);
    fakeTools();
    $conversion = queueConversion(convertibleGame('ps2', ['Game.iso' => 4096]), 'chd-dvd');
    $held = Cache::lock('conversion.slot.1', 60);
    $held->get();

    expect(runConversion($conversion)->status)->toBe(ConversionStatus::Queued);

    Process::assertNothingRan();

    $held->release();

    expect(runConversion($conversion)->status)->toBe(ConversionStatus::Done);
});

it('clears the finished conversions and leaves the rest', function () {
    fakeTools();
    $game = convertibleGame('ps2', ['Game.iso' => 4096]);
    runConversion(queueConversion($game, 'cso'));
    $waiting = queueConversion($game, 'zso');

    expect(app(ConversionQueue::class)->clearFinished())->toBe(1)
        ->and(Conversion::query()->pluck('id')->all())->toBe([$waiting->id]);
});

/** A PS1 cue sheet cue2pops takes as it is, over the placeholder convertibleGame() wrote. */
function vcdSheet(string $relative, string $contents): void
{
    File::put(test()->root.'/'.$relative, $contents);
}

it('turns a PS1 cue sheet into a VCD with cue2pops, whose 1 means it worked', function () {
    fakeTools();
    $game = convertibleGame('psx', ['Crash/Crash.cue' => 80, 'Crash/Crash.bin' => 2352 * 4]);
    vcdSheet('psx/Crash/Crash.cue', "FILE \"Crash.bin\" BINARY\n  TRACK 01 MODE2/2352\n    INDEX 01 00:00:00\n");

    $conversion = runConversion(queueConversion($game, 'vcd'));

    expect($conversion->status)->toBe(ConversionStatus::Done)
        ->and($conversion->outputs)->toBe(['Crash.VCD'])
        ->and(File::exists($this->root.'/psx/Crash/Crash.VCD'))->toBeTrue()
        ->and($this->ran)->toBe(['cue2pops Crash.cue']);
});

it('fails a VCD when cue2pops returns 0, which is its failure', function () {
    fakeTools(['cue2pops' => 0]);
    $game = convertibleGame('psx', ['Crash.cue' => 80, 'Crash.bin' => 2352 * 4]);
    vcdSheet('psx/Crash.cue', "FILE \"Crash.bin\" BINARY\n  TRACK 01 MODE2/2352\n    INDEX 01 00:00:00\n");

    expect(runConversion(queueConversion($game, 'vcd')))
        ->status->toBe(ConversionStatus::Failed)
        ->failure->toBe(ConversionFailure::ToolFailed);
});

it('merges a dump split into a BIN per track with chdman before cue2pops', function () {
    fakeTools();
    $game = convertibleGame('psx', ['Tomb.cue' => 80, 'Tomb (Track 1).bin' => 2352 * 4, 'Tomb (Track 2).bin' => 2352 * 2]);
    vcdSheet('psx/Tomb.cue', "FILE \"Tomb (Track 1).bin\" BINARY\n  TRACK 01 MODE2/2352\n    INDEX 01 00:00:00\nFILE \"Tomb (Track 2).bin\" BINARY\n  TRACK 02 AUDIO\n    INDEX 01 00:00:00\n");

    $conversion = runConversion(queueConversion($game, 'vcd', [Converter::ADVANCED => ['gap' => 'plus', 'vmode' => 'on']]));

    expect($conversion->status)->toBe(ConversionStatus::Done)
        ->and($this->ran)->toBe(['chdman createcd', 'chdman extractcd', 'cue2pops Tomb.merged.cue'])
        ->and($conversion->log)->toContain('Tomb.merged.cue gap++ vmode')
        ->and(File::exists($this->root.'/psx/Tomb.VCD'))->toBeTrue()
        ->and(File::exists(stagingOf($conversion)))->toBeFalse();
});

it('turns a VCD back into a cue and a BIN, and leaves the VCD as it was', function () {
    fakeTools();
    $game = convertibleGame('psx', ['Crash.VCD' => 2352 * 4]);
    $before = md5_file($this->root.'/psx/Crash.VCD');

    $conversion = runConversion(queueConversion($game, 'vcd-to-cue'));

    expect($conversion->status)->toBe(ConversionStatus::Done)
        ->and($conversion->outputs)->toBe(['Crash.cue', 'Crash.bin'])
        ->and(File::exists($this->root.'/psx/Crash.cue'))->toBeTrue()
        ->and(md5_file($this->root.'/psx/Crash.VCD'))->toBe($before)
        ->and(File::exists(stagingOf($conversion)))->toBeFalse();
});

it('turns a Wii disc into RVZ at the zstd level asked for, and WBFS scrubbed when asked', function () {
    fakeTools();
    $game = convertibleGame('wii', ['Zelda.iso' => 4096]);

    $rvz = runConversion(queueConversion($game, 'rvz', [Converter::ADVANCED => ['level' => '19']]));
    $wbfs = runConversion(queueConversion($game, 'wbfs', [Converter::ADVANCED => ['scrub' => 'on']]));

    expect($rvz->status)->toBe(ConversionStatus::Done)
        ->and($rvz->log)->toContain('nodtool convert -c zstd:19 wii/Zelda.iso')
        ->and($wbfs->log)->toContain('nodtool convert --scrub wii/Zelda.iso')
        ->and(File::exists($this->root.'/wii/Zelda.rvz'))->toBeTrue()
        ->and(File::exists($this->root.'/wii/Zelda.wbfs'))->toBeTrue();
});

it('fails a nodtool verify that finds a hash off, though nodtool exits 0', function () {
    fakeTools([], "Redump: Not found ❌\nCRC32 : 1234abcd ❌ (expected: 99999999)\n");

    $conversion = runConversion(queueConversion(convertibleGame('gc', ['Melee.iso' => 4096]), 'rvz', [Converter::VERIFY => true]));

    expect($conversion->failure)->toBe(ConversionFailure::VerifyFailed)
        ->and(File::exists($this->root.'/gc/Melee.rvz'))->toBeFalse();
});

it('passes a nodtool verify whose hashes all match', function () {
    fakeTools([], "Redump: Melee (USA) ✅\nCRC32 : 1234abcd ✅\n");

    $conversion = runConversion(queueConversion(convertibleGame('gc', ['Melee.iso' => 4096]), 'rvz', [Converter::VERIFY => true]));

    expect($conversion->status)->toBe(ConversionStatus::Done)
        ->and($this->ran)->toContain('nodtool verify');
});

it('records a conversion that runs out of time as timed out, and stops the tool', function () {
    // A real tool, not a fake: only a real process can outrun its timeout.
    File::put($this->root.'/bin/maxcso', "#!/bin/sh\nsleep 5\n");
    config()->set('converters.timeout', 1);

    $started = microtime(true);
    $conversion = runConversion(queueConversion(convertibleGame('ps2', ['Game.iso' => 4096]), 'cso'));

    expect($conversion->status)->toBe(ConversionStatus::Failed)
        ->and($conversion->failure)->toBe(ConversionFailure::TimedOut)
        ->and(microtime(true) - $started)->toBeLessThan(4.5)
        ->and(File::exists(stagingOf($conversion)))->toBeFalse();
});

it('keeps a conversion\'s time under the long connection\'s retry_after, whatever .env says', function () {
    config()->set('converters.timeout', 9000);
    config()->set('queue.connections.database-long.retry_after', 7200);

    expect(ConversionRunner::timeout())->toBe(7080);

    config()->set('converters.timeout', 600);

    expect(ConversionRunner::timeout())->toBe(600);
});

it('adds up stats from conversions that finish at once', function () {
    ConversionStat::record('cso', 'ps2', 1000, 800);
    ConversionStat::record('cso', 'ps2', 3000, 2400);

    $stat = ConversionStat::query()->sole();

    expect($stat->conversions)->toBe(2)
        ->and($stat->source_bytes)->toBe(4000)
        ->and($stat->output_bytes)->toBe(3200)
        ->and(ConversionStat::ratio('cso', 'ps2'))->toBe(0.8);
});

it('merges each disc of a split multi-disc set into a file of its own', function () {
    fakeTools();
    $game = convertibleGame('psx', [
        'Set/Set (Disc 1).cue' => 80, 'Set/Set (Disc 1) (Track 1).bin' => 2352, 'Set/Set (Disc 1) (Track 2).bin' => 2352,
        'Set/Set (Disc 2).cue' => 80, 'Set/Set (Disc 2) (Track 1).bin' => 2352, 'Set/Set (Disc 2) (Track 2).bin' => 2352,
        'Set/Set.m3u' => 10,
    ]);
    foreach ([1, 2] as $disc) {
        vcdSheet('psx/Set/Set (Disc '.$disc.').cue', "FILE \"Set (Disc {$disc}) (Track 1).bin\" BINARY\n  TRACK 01 MODE2/2352\n    INDEX 01 00:00:00\nFILE \"Set (Disc {$disc}) (Track 2).bin\" BINARY\n  TRACK 02 AUDIO\n    INDEX 01 00:00:00\n");
    }
    $playlist = $game->files()->where('role', FileRole::Playlist)->firstOrFail();
    $game->files()->where('role', FileRole::Sheet)->update(['parent_id' => $playlist->id]);

    $conversion = runConversion(queueConversion($game, 'vcd'));

    expect($conversion->status)->toBe(ConversionStatus::Done)
        ->and($conversion->log)->toContain('Set (Disc 1).merge.chd')
        ->and($conversion->log)->toContain('Set (Disc 2).merge.chd');
});

it('asks a conversion the worker picked up meanwhile to stop, rather than overwriting it', function () {
    fakeTools();
    $conversion = queueConversion(convertibleGame('ps2', ['Game.iso' => 4096]), 'cso');

    // The page still has it as queued; the worker has started it since.
    Conversion::query()->whereKey($conversion->id)->update(['status' => ConversionStatus::Running]);

    app(ConversionQueue::class)->cancel($conversion);

    expect($conversion->fresh())
        ->status->toBe(ConversionStatus::Running)
        ->cancel_requested_at->not->toBeNull();
});

it('stays done when recording its stats fails afterwards', function () {
    fakeTools();
    $conversion = queueConversion(convertibleGame('ps2', ['Game.iso' => 4096]), 'cso');

    // Recording the stats throws, once the output is already in.
    ConversionStat::creating(function (): void {
        throw new RuntimeException('stats down');
    });

    expect(runConversion($conversion)->status)->toBe(ConversionStatus::Done)
        ->and(File::exists($this->root.'/ps2/Game.cso'))->toBeTrue()
        ->and($conversion->fresh()->log)->toContain('A step after the conversion failed');
});
