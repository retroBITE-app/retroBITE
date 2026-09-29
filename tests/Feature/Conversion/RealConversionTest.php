<?php

use App\Conversion\ConversionQueue;
use App\Conversion\ConversionRunner;
use App\Conversion\Converter;
use App\Conversion\SourceSet;
use App\Conversion\Tools;
use App\Enums\ConversionStatus;
use App\Enums\FileRole;
use App\Jobs\RunConversion;
use App\Models\ConsoleSourceFolder;
use App\Models\Conversion;
use App\Models\Game;
use App\Models\GameFile;
use App\Support\Console;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * One real conversion per tool, there and back, with nothing faked: the
 * binaries the web image ships, on small generated images. Each round trip
 * has to come back byte for byte, which is also what proves the arguments
 * each converter builds are the ones the tool takes.
 *
 * Skipped where a tool is not installed — outside the container, that is.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-real-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/ps2');
    File::ensureDirectoryExists($this->root.'/psx');
    File::ensureDirectoryExists($this->root.'/gc');
    config()->set('settings.games_path', $this->root);

    ConsoleSourceFolder::add(new Console('ps2'));
    ConsoleSourceFolder::add(new Console('psx'));
    ConsoleSourceFolder::add(new Console('gc'));

    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function requireTools(string ...$tools): void
{
    foreach ($tools as $tool) {
        if (! Tools::available($tool)) {
            test()->markTestSkipped($tool.' is not installed');
        }
    }
}

/** Random bytes, so no compressor can make the round trip trivially. */
function randomImage(string $relative, int $bytes): string
{
    $path = test()->root.'/'.$relative;
    File::ensureDirectoryExists(dirname($path));
    File::put($path, random_bytes($bytes));

    return md5_file($path);
}

/** A row for a file on disk, as the scanner would have written it. */
function recordOnDisk(Game $game, string $relative, FileRole $role = FileRole::Rom, ?GameFile $parent = null): GameFile
{
    return GameFile::factory()->for($game)->create([
        'path' => $relative,
        'filename' => basename($relative),
        'extension' => Str::lower(pathinfo($relative, PATHINFO_EXTENSION)),
        'size_bytes' => filesize(test()->root.'/'.$relative),
        'role' => $role,
        'parent_id' => $parent?->id,
    ]);
}

/** @param  array<string, bool|string>  $options */
function convertForReal(GameFile $file, string $converter, array $options = []): Conversion
{
    $conversion = app(ConversionQueue::class)->add(SourceSet::fromFile($file), $converter, $options);

    (new RunConversion($conversion->id))->handle(app(ConversionRunner::class));

    $conversion->refresh();

    expect($conversion->status)->toBe(ConversionStatus::Done, (string) $conversion->log);

    return $conversion;
}

it('takes a PS1 cue sheet to CHD and back with chdman', function () {
    requireTools('chdman');

    $game = Game::factory()->forConsole('psx')->create();
    $md5 = randomImage('psx/Game.bin', 2352 * 300);
    File::put($this->root.'/psx/Game.cue', "FILE \"Game.bin\" BINARY\n  TRACK 01 MODE2/2352\n    INDEX 01 00:00:00\n");
    $sheet = recordOnDisk($game, 'psx/Game.cue', FileRole::Sheet);
    recordOnDisk($game, 'psx/Game.bin', FileRole::Track, $sheet);

    convertForReal($sheet, 'chd-cd', [Converter::VERIFY => true, Converter::KEEP_SOURCE => false]);

    expect(File::exists($this->root.'/psx/Game.chd'))->toBeTrue()
        ->and(File::exists($this->root.'/psx/Game.bin'))->toBeFalse();

    convertForReal(recordOnDisk($game, 'psx/Game.chd'), 'chd-to-cue');

    expect(md5_file($this->root.'/psx/Game.bin'))->toBe($md5)
        ->and(File::get($this->root.'/psx/Game.cue'))->toContain('FILE "Game.bin" BINARY');
});

it('takes a bare PS1 image with no sheet to CHD with chdman', function () {
    requireTools('chdman');

    $game = Game::factory()->forConsole('psx')->create();
    $md5 = randomImage('psx/Crash/Crash.bin', 2352 * 200);

    convertForReal(recordOnDisk($game, 'psx/Crash/Crash.bin'), 'chd-cd', [Converter::KEEP_SOURCE => false]);
    convertForReal(recordOnDisk($game, 'psx/Crash/Crash.chd'), 'chd-to-cue');

    expect(md5_file($this->root.'/psx/Crash/Crash.bin'))->toBe($md5);
});

it('takes a PS2 ISO to CHD and back with chdman, verifying on the way', function () {
    requireTools('chdman');

    $game = Game::factory()->forConsole('ps2')->create();
    $md5 = randomImage('ps2/Game.iso', 2048 * 2000);

    $conversion = convertForReal(recordOnDisk($game, 'ps2/Game.iso'), 'chd-dvd', [Converter::VERIFY => true, Converter::KEEP_SOURCE => false]);

    expect($conversion->log)->toContain('verify');

    convertForReal(recordOnDisk($game, 'ps2/Game.chd'), 'chd-to-iso');

    expect(md5_file($this->root.'/ps2/Game.iso'))->toBe($md5);
});

it('takes a PS2 ISO to CSO and to ZSO and back with maxcso', function (string $format) {
    requireTools('maxcso');

    $game = Game::factory()->forConsole('ps2')->create();
    $md5 = randomImage('ps2/Game.iso', 2048 * 2000);

    convertForReal(recordOnDisk($game, 'ps2/Game.iso'), $format, [Converter::KEEP_SOURCE => false]);
    convertForReal(recordOnDisk($game, 'ps2/Game.'.$format), 'cso-to-iso');

    expect(md5_file($this->root.'/ps2/Game.iso'))->toBe($md5);
})->with(['cso', 'zso']);

it('takes a PS1 image to ECM and back with ecm and unecm', function () {
    requireTools('ecm', 'unecm');

    $game = Game::factory()->forConsole('psx')->create();
    $md5 = randomImage('psx/Game.bin', 2352 * 300);

    convertForReal(recordOnDisk($game, 'psx/Game.bin'), 'ecm', [Converter::KEEP_SOURCE => false]);

    expect(File::exists($this->root.'/psx/Game.bin.ecm'))->toBeTrue();

    convertForReal(recordOnDisk($game, 'psx/Game.bin.ecm'), 'unecm');

    expect(md5_file($this->root.'/psx/Game.bin'))->toBe($md5);
});

it('reports every tool it ships at the version it pins', function () {
    requireTools('chdman', 'maxcso', 'ecm', 'unecm', 'extract-xiso');

    foreach (Tools::report() as $tool) {
        expect(Arr::get($tool, 'found'))->toBe(Arr::get($tool, 'pinned'), (string) Arr::get($tool, 'label'));
    }
});

it('takes a PS1 cue sheet to a POPStarter VCD and back with cue2pops and pops2cue', function () {
    requireTools('cue2pops', 'pops2cue');

    $game = Game::factory()->forConsole('psx')->create();
    $md5 = randomImage('psx/Game.bin', 2352 * 300);
    File::put($this->root.'/psx/Game.cue', "FILE \"Game.bin\" BINARY\n  TRACK 01 MODE2/2352\n    INDEX 01 00:00:00\n");
    $sheet = recordOnDisk($game, 'psx/Game.cue', FileRole::Sheet);
    recordOnDisk($game, 'psx/Game.bin', FileRole::Track, $sheet);

    convertForReal($sheet, 'vcd', [Converter::KEEP_SOURCE => false]);

    // The disc unchanged behind a 1 MiB header.
    expect(filesize($this->root.'/psx/Game.VCD'))->toBe(2352 * 300 + 1048576)
        ->and(File::exists($this->root.'/psx/Game.bin'))->toBeFalse();

    convertForReal(recordOnDisk($game, 'psx/Game.VCD'), 'vcd-to-cue');

    expect(md5_file($this->root.'/psx/Game.bin'))->toBe($md5)
        ->and(File::get($this->root.'/psx/Game.cue'))->toContain('FILE "Game.bin" BINARY');
});

it('merges a dump split into a BIN per track before making its VCD', function () {
    requireTools('cue2pops', 'chdman');

    $game = Game::factory()->forConsole('psx')->create();
    randomImage('psx/Tomb (Track 1).bin', 2352 * 300);
    randomImage('psx/Tomb (Track 2).bin', 2352 * 150);
    File::put($this->root.'/psx/Tomb.cue', "FILE \"Tomb (Track 1).bin\" BINARY\n  TRACK 01 MODE2/2352\n    INDEX 01 00:00:00\nFILE \"Tomb (Track 2).bin\" BINARY\n  TRACK 02 AUDIO\n    INDEX 00 00:00:00\n    INDEX 01 00:02:00\n");
    $sheet = recordOnDisk($game, 'psx/Tomb.cue', FileRole::Sheet);
    recordOnDisk($game, 'psx/Tomb (Track 1).bin', FileRole::Track, $sheet);
    recordOnDisk($game, 'psx/Tomb (Track 2).bin', FileRole::Track, $sheet);

    convertForReal($sheet, 'vcd');

    expect(filesize($this->root.'/psx/Tomb.VCD'))->toBe(2352 * 450 + 1048576);
});

/**
 * A GameCube disc nodtool will take: a boot header with the GameCube magic,
 * an empty file system table, and random data after it. Sparse, so its size
 * costs nothing but the random part.
 */
function gameCubeImage(string $relative, int $bytes): string
{
    $path = test()->root.'/'.$relative;
    $header = str_pad('GTEST1', 0x1C, "\0").pack('N', 0xC2339F3D).str_pad('retroBITE test disc', 0x400, "\0");
    $header = str_pad($header, 0x420, "\0").pack('NNNN', 0x40000, 0x50000, 0x20, 0x20);

    $handle = fopen($path, 'wb');
    fwrite($handle, str_pad($header, 0x440, "\0"));
    fseek($handle, 0x50000);
    fwrite($handle, pack('CCnNN', 1, 0, 0, 0, 1).str_repeat("\0", 20));
    fseek($handle, 0x100000);
    fwrite($handle, random_bytes($bytes - 0x200000));
    ftruncate($handle, $bytes);
    fclose($handle);

    return md5_file($path);
}

it('takes a GameCube disc to RVZ and back with nodtool, verifying on the way', function () {
    requireTools('nodtool');

    $game = Game::factory()->forConsole('gc')->create();
    $md5 = gameCubeImage('gc/Game.iso', 32 * 1024 * 1024);

    $conversion = convertForReal(recordOnDisk($game, 'gc/Game.iso'), 'rvz', [Converter::VERIFY => true, Converter::KEEP_SOURCE => false]);

    expect($conversion->log)->toContain('nodtool verify')
        ->and($conversion->log)->not->toContain('(expected: ');

    convertForReal(recordOnDisk($game, 'gc/Game.rvz'), 'nod-to-iso');

    expect(md5_file($this->root.'/gc/Game.iso'))->toBe($md5);
});
