<?php

use App\Conversion\Converter;
use App\Conversion\Converters;
use App\Conversion\Converters\ChdmanConverter;
use App\Conversion\Converters\CreateCdChd;
use App\Conversion\SourceSet;
use App\Conversion\Tools;
use App\Enums\FileRole;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Support\Console;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The converter registry and the one gate: which formats a source on a
 * console may become, and through which converter. Tools are stand-in
 * executables here, so what is installed is the test's to decide.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-conversion-'.Str::random(8);
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
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/**
 * A game with these files, on disk and on record. A cue's tracks follow it.
 *
 * @param  array<string, int>  $files  name => size in bytes
 */
function registryGame(string $console, array $files): Game
{
    $game = Game::factory()->forConsole($console)->create();
    $sheet = null;

    foreach ($files as $name => $size) {
        $extension = Str::lower(pathinfo($name, PATHINFO_EXTENSION));
        $role = match ($extension) {
            'cue' => FileRole::Sheet,
            'm3u' => FileRole::Playlist,
            default => $sheet !== null && $extension === 'bin' ? FileRole::Track : FileRole::Rom,
        };

        File::ensureDirectoryExists(dirname(test()->root.'/'.$console.'/'.$name));
        $handle = fopen(test()->root.'/'.$console.'/'.$name, 'w');
        ftruncate($handle, $size);
        fclose($handle);

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

/** @return list<string> */
function routesOf(Game $game): array
{
    $set = SourceSet::fromFile($game->files()->whereNull('parent_id')->orderBy('id')->firstOrFail());

    return Converters::routesFor($set)->map(function (Converter $converter): string {
        return $converter->key();
    })->all();
}

it('makes a converter by key, and nothing for a key it does not carry', function () {
    config()->set('converters.converters.bogus', stdClass::class);

    expect(Converters::make('chd-cd'))->toBeInstanceOf(CreateCdChd::class)
        ->and(Converters::make('nope'))->toBeNull()
        ->and(Converters::make('bogus'))->toBeNull()
        ->and(Converters::make(null))->toBeNull()
        ->and(Converters::all()->count())->toBe(15);
});

it('offers a PS2 DVD image CHD, CSO and ZSO', function () {
    expect(routesOf(registryGame('ps2', ['Game.iso' => 2048 * 100])))->toBe(['chd-dvd', 'cso', 'zso']);
});

it('offers a PS2 CD as a CD, by its cue or by its raw sectors', function () {
    expect(routesOf(registryGame('ps2', ['Game.cue' => 80, 'Game.bin' => 2352 * 100])))->toBe(['chd-cd'])
        ->and(routesOf(registryGame('ps2', ['Raw.img' => 2352 * 100])))->toBe(['chd-cd'])
        ->and(routesOf(registryGame('ps2', ['Dvd.img' => 2048 * 101])))->toBe(['chd-dvd']);
});

it('never offers a PS1 disc a format no PS1 frontend reads', function () {
    expect(routesOf(registryGame('psx', ['Game.iso' => 2048 * 100])))->toBe(['chd-cd', 'ecm'])
        ->and(routesOf(registryGame('psx', ['One.cue' => 80, 'One.bin' => 2352 * 100])))->toBe(['chd-cd', 'ecm'])
        ->and(routesOf(registryGame('psx', ['Two.cue' => 80, 'Two (Track 1).bin' => 2352 * 100, 'Two (Track 2).bin' => 2352 * 50])))->toBe(['chd-cd'])
        ->and(routesOf(registryGame('psx', ['Game.bin.ecm' => 1000])))->toBe(['unecm']);
});

it('asks a PS2 CHD whether it holds a CD or a DVD', function () {
    Process::fake([
        '*Cd.chd*' => Process::result("Metadata:     Tag='CHT2'  Index=0  Length=91 bytes\n"),
        '*Dvd.chd*' => Process::result("Metadata:     Tag='DVD '  Index=0  Length=1 bytes\n"),
    ]);

    expect(routesOf(registryGame('ps2', ['Cd.chd' => 5000])))->toBe(['chd-to-cue'])
        ->and(routesOf(registryGame('ps2', ['Dvd.chd' => 5000])))->toBe(['chd-to-iso']);
});

it('offers a PS1 CHD as BIN/CUE without asking', function () {
    Process::fake();

    expect(routesOf(registryGame('psx', ['Game.chd' => 5000])))->toBe(['chd-to-cue']);

    Process::assertNothingRan();
});

it('turns off only the formats whose tool is missing', function () {
    config()->set('converters.tools.chdman.path', $this->root.'/bin/not-here');

    $set = SourceSet::fromFile(registryGame('ps2', ['Game.iso' => 2048 * 100])->files()->firstOrFail());

    expect(Tools::available('chdman'))->toBeFalse()
        ->and(Tools::available('maxcso'))->toBeTrue()
        ->and(Converters::routesFor($set)->map->key()->all())->toBe(['cso', 'zso'])
        ->and(Converters::candidatesFor($set)->map->key()->all())->toBe(['chd-dvd', 'cso', 'zso']);
});

it('offers nothing on a console no converter names', function () {
    expect(Converters::offersOn(new Console('snes')))->toBeFalse()
        ->and(Converters::offersOn(new Console('ps2')))->toBeTrue()
        ->and(Converters::readableOn(new Console('psx')))->not->toContain('cso');
});

it('converts a playlist and its discs as one set', function () {
    $game = registryGame('psx', [
        'FF9/FF9 (Disc 2).cue' => 80, 'FF9/FF9 (Disc 2).bin' => 2352 * 10,
        'FF9/FF9 (Disc 1).cue' => 80, 'FF9/FF9 (Disc 1).bin' => 2352 * 10,
        'FF9/FF9.m3u' => 40,
    ]);
    $playlist = $game->files()->where('role', FileRole::Playlist)->firstOrFail();
    $game->files()->where('role', FileRole::Sheet)->each(function (GameFile $sheet) use ($playlist): void {
        $sheet->update(['parent_id' => $playlist->id, 'disc_number' => (int) Str::match('/Disc (\d)/', $sheet->filename)]);
    });

    $sets = SourceSet::forConsole(new Console('psx'));

    expect($sets)->toHaveCount(1)
        ->and($sets->first()->isSet())->toBeTrue()
        ->and($sets->first()->label())->toBe('FF9.m3u')
        ->and($sets->first()->directory)->toBe('FF9')
        ->and(array_map(function ($disc): string {
            return $disc->file->filename;
        }, $sets->first()->discs))->toBe(['FF9 (Disc 1).cue', 'FF9 (Disc 2).cue'])
        ->and($sets->first()->files())->toHaveCount(5);
});

it('groups loose numbered discs of one folder into a set, and leaves the rest alone', function () {
    registryGame('psx', ['MGS (Disc 2).chd' => 100, 'MGS (Disc 1).chd' => 100]);
    registryGame('psx', ['Crash.chd' => 100]);

    $sets = SourceSet::forConsole(new Console('psx'));

    expect($sets->map->label()->all())->toBe(['Crash.chd', 'MGS (Disc 1).chd'])
        ->and($sets->last()->isSet())->toBeTrue()
        ->and($sets->last()->stem())->toBe('MGS');
});

it('reads chdman\'s progress line', function () {
    $converter = new CreateCdChd;

    expect($converter->progress('Compressing, 45.2% complete... (ratio=70.8%)'))->toBe(45.2)
        ->and($converter->progress('Verifying, 99.9% complete...'))->toBe(99.9)
        ->and($converter->progress('Compression complete ... final ratio = 70.8%'))->toBeNull()
        ->and($converter)->toBeInstanceOf(ChdmanConverter::class);
});

/** Write a cue sheet over the placeholder registryGame() made. */
function registrySheet(string $relative, string $contents): void
{
    File::put(test()->root.'/'.$relative, $contents);
}

it('offers a PS1 data disc as a POPStarter VCD, split into a BIN per track or not', function () {
    $single = registryGame('psx', ['One.cue' => 80, 'One.bin' => 2352 * 10]);
    registrySheet('psx/One.cue', "FILE \"One.bin\" BINARY\n  TRACK 01 MODE2/2352\n    INDEX 01 00:00:00\n");

    $split = registryGame('psx', ['Two.cue' => 80, 'Two (Track 1).bin' => 2352 * 10, 'Two (Track 2).bin' => 2352 * 5]);
    registrySheet('psx/Two.cue', "FILE \"Two (Track 1).bin\" BINARY\n  TRACK 01 MODE2/2352\n    INDEX 01 00:00:00\nFILE \"Two (Track 2).bin\" BINARY\n  TRACK 02 AUDIO\n    INDEX 01 00:00:00\n");

    expect(routesOf($single))->toContain('vcd')
        ->and(routesOf($split))->toContain('vcd');
});

it('never offers a VCD for a sheet cue2pops cannot take, or off PS1', function () {
    $wave = registryGame('psx', ['Wav.cue' => 80, 'Wav.bin' => 2352 * 10]);
    registrySheet('psx/Wav.cue', "FILE \"Wav.bin\" BINARY\n  TRACK 01 MODE2/2352\n    INDEX 01 00:00:00\nFILE \"Track 2.wav\" WAVE\n  TRACK 02 AUDIO\n    INDEX 01 00:00:00\n");

    $mode1 = registryGame('psx', ['Pc.cue' => 80, 'Pc.bin' => 2352 * 10]);
    registrySheet('psx/Pc.cue', "FILE \"Pc.bin\" BINARY\n  TRACK 01 MODE1/2352\n    INDEX 01 00:00:00\n");

    $ps2 = registryGame('ps2', ['Cd.cue' => 80, 'Cd.bin' => 2352 * 10]);
    registrySheet('ps2/Cd.cue', "FILE \"Cd.bin\" BINARY\n  TRACK 01 MODE2/2352\n    INDEX 01 00:00:00\n");

    expect(routesOf($wave))->not->toContain('vcd')
        ->and(routesOf($mode1))->not->toContain('vcd')
        ->and(routesOf($ps2))->not->toContain('vcd');
});

it('offers a VCD back as BIN/CUE', function () {
    expect(routesOf(registryGame('psx', ['Game.VCD' => 2352 * 10 + 1048576])))->toBe(['vcd-to-cue']);
});

it('turns VCD off when either of the tools it runs is missing', function () {
    $game = registryGame('psx', ['One.cue' => 80, 'One.bin' => 2352 * 10]);
    registrySheet('psx/One.cue', "FILE \"One.bin\" BINARY\n  TRACK 01 MODE2/2352\n    INDEX 01 00:00:00\n");
    $set = SourceSet::fromFile($game->files()->whereNull('parent_id')->firstOrFail());

    config()->set('converters.tools.chdman.path', $this->root.'/bin/gone');

    expect(Converters::routesFor($set)->map->key()->all())->not->toContain('vcd')
        ->and(Converters::candidatesFor($set)->map->key()->all())->toContain('vcd')
        ->and(Tools::missing(Converters::make('vcd')))->toBe('chdman');
});

it('offers what the console\'s own file lists, in its order, and nothing else', function () {
    config()->set('consoles.ps2.converters', ['zso', 'no-such-converter', 'cso']);

    expect(routesOf(registryGame('ps2', ['Game.iso' => 2048 * 100])))->toBe(['zso', 'cso']);

    config()->set('consoles.ps2.converters', []);

    expect(Converters::offersOn(new Console('ps2')))->toBeFalse();
});

it('treats a console with no DVD conversion as all CDs', function () {
    // PS2 without its DVD conversions: an ISO there is now a CD image.
    config()->set('consoles.ps2.converters', ['chd-cd', 'chd-to-cue']);

    Process::fake();

    expect(routesOf(registryGame('ps2', ['Game.iso' => 2048 * 100])))->toBe(['chd-cd'])
        ->and(routesOf(registryGame('ps2', ['Game.chd' => 5000])))->toBe(['chd-to-cue']);

    // Nothing had to be asked whether the CHD holds a CD.
    Process::assertNothingRan();
});

it('has every console declare its conversions, each one registered, and its decrypters likewise', function () {
    $converters = array_keys((array) config('converters.converters'));
    $decrypters = array_keys((array) config('decrypters.decrypters'));

    foreach (glob(config_path('consoles/*.php')) as $file) {
        $meta = require $file;

        expect(Arr::has($meta, 'converters'))->toBeTrue(basename($file).' declares no converters');

        foreach ((array) Arr::get($meta, 'converters') as $key) {
            expect($converters)->toContain($key);
        }

        foreach ((array) Arr::get($meta, 'decrypters', []) as $key) {
            expect($decrypters)->toContain($key);
        }
    }
});

it('offers a GameCube disc RVZ and back to ISO, and never WBFS', function () {
    expect(routesOf(registryGame('gc', ['Melee.iso' => 2048 * 100])))->toBe(['rvz'])
        ->and(routesOf(registryGame('gc', ['Melee.rvz' => 5000])))->toBe(['nod-to-iso'])
        ->and(routesOf(registryGame('gc', ['Melee.ciso' => 5000])))->toBe(['rvz', 'nod-to-iso']);
});

it('offers a Wii disc RVZ, WBFS, and the plain ISO USB Loader GX reads', function () {
    expect(routesOf(registryGame('wii', ['Zelda.iso' => 2048 * 100])))->toBe(['rvz', 'wbfs'])
        ->and(routesOf(registryGame('wii', ['Zelda.rvz' => 5000])))->toBe(['wbfs', 'nod-to-iso'])
        ->and(routesOf(registryGame('wii', ['Zelda.wbfs' => 5000])))->toBe(['rvz', 'nod-to-iso'])
        ->and(routesOf(registryGame('wii', ['Zelda.wia' => 5000])))->toBe(['rvz', 'wbfs', 'nod-to-iso'])
        ->and(routesOf(registryGame('wii', ['Zelda.gcz' => 5000])))->toBe(['rvz', 'wbfs', 'nod-to-iso']);
});

it('offers GameCube and Wii nothing without nodtool', function () {
    config()->set('converters.tools.nodtool.path', $this->root.'/bin/gone');

    expect(routesOf(registryGame('gc', ['Melee.iso' => 2048 * 100])))->toBe([])
        ->and(routesOf(registryGame('wii', ['Zelda.wbfs' => 5000])))->toBe([]);
});

it('keeps the picker\'s converters and a page\'s apart, null being the picker', function () {
    expect(Converters::onPage(new Console('ps3'), null)->all())->toBe([])
        ->and(Converters::onPage(new Console('ps3'), Converter::PAGE_DECRYPT)->map->key()->all())->toBe(['ps3-decrypt'])
        ->and(Converters::onPage(new Console('psx'), null)->map->key()->all())->toBe(['chd-cd', 'chd-to-cue', 'ecm', 'unecm', 'vcd', 'vcd-to-cue'])
        ->and(Converters::offersOn(new Console('psx')))->toBeTrue();
});
