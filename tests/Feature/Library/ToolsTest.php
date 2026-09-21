<?php

use App\Jobs\InspectGameFile;
use App\Jobs\ScanConsoleFolder;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Services\LibraryScanner;
use App\Support\Console;
use App\Tools\ConsoleTools;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The toolbox is the seam between the application and one console's shell
 * scripts. What matters here is that it stays a seam: a script that fails says
 * so in the log and nothing else, a compressed image is refused rather than
 * read and found empty, and a filename that already carries its serial never
 * starts a process at all.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-tools-'.Str::random(8);
    File::ensureDirectoryExists($this->root);
    config()->set('settings.games_path', $this->root);

    Process::preventStrayProcesses();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/** The PS2 toolbox as every caller gets one: resolved for its console. */
function ps2Tools(): ConsoleTools
{
    $tools = ConsoleTools::for(new Console('ps2'));

    expect($tools)->not->toBeNull();

    return $tools;
}

function ps2File(string $path = 'ps2/DVD/Castlevania (Europe).iso'): GameFile
{
    $game = Game::factory()->create(['console' => 'ps2']);

    return GameFile::factory()->for($game)->create([
        'path' => $path,
        'filename' => basename($path),
        'extension' => pathinfo($path, PATHINFO_EXTENSION),
    ]);
}

it('writes what the script reported onto the file', function () {
    Process::fake([
        '*' => Process::result(
            "license_id\tSLES_521.18\ncover_id\tSLES-52118\nregion\tEurope\nvideo_mode\tPAL\n"
        ),
    ]);

    $file = ps2File();

    (new InspectGameFile($file->id))->handle();

    expect($file->refresh()->license_id)->toBe('SLES_521.18')
        ->and($file->video_mode)->toBe('PAL')
        ->and($file->region)->toBe('Europe');
});

it('leaves the file alone when the script found nothing', function () {
    // Exit 1 is the scripts' "no serial here" — an answer, not a failure, so
    // nothing is written and nothing is logged as an error.
    Process::fake(['*' => Process::result(output: '', exitCode: 1)]);

    $file = ps2File();

    (new InspectGameFile($file->id))->handle();

    expect($file->refresh()->license_id)->toBeNull()
        ->and($file->video_mode)->toBeNull();
});

it('survives a script that fell over', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'bash: strings: not found', exitCode: 127)]);

    $file = ps2File();

    (new InspectGameFile($file->id))->handle();

    expect($file->refresh()->license_id)->toBeNull();
});

it('does not open a compressed image', function () {
    Process::fake();

    $file = ps2File('ps2/DVD/Castlevania.chd');

    (new InspectGameFile($file->id))->handle();

    Process::assertNothingRan();
    expect($file->refresh()->license_id)->toBeNull();
});

it('does not re-read a file it has already read', function () {
    Process::fake();

    $file = ps2File();
    $file->update(['license_id' => 'SLES_521.18']);

    (new InspectGameFile($file->id))->handle();

    Process::assertNothingRan();
});

it('re-reads one when told to', function () {
    Process::fake(['*' => Process::result("license_id\tSLES_503.86\n")]);

    $file = ps2File();
    $file->update(['license_id' => 'WRONG_000.00']);

    (new InspectGameFile($file->id, force: true))->handle();

    expect($file->refresh()->license_id)->toBe('SLES_503.86');
});

it('keeps a region the provider already gave', function () {
    Process::fake(['*' => Process::result("license_id\tSLES_503.86\nregion\tEurope\n")]);

    $file = ps2File();
    $file->update(['region' => 'USA']);

    (new InspectGameFile($file->id))->handle();

    // The serial can only say which of five territories pressed the disc. A
    // region already on the row came from somewhere more precise.
    expect($file->refresh()->region)->toBe('USA');
});

it('queues nothing for a console with no toolbox', function () {
    Process::fake();

    $game = Game::factory()->create(['console' => 'snes']);
    GameFile::factory()->for($game)->create(['path' => 'snes/Mario.sfc', 'extension' => 'sfc']);

    expect(InspectGameFile::queueAwaiting('snes'))->toBe(0);
});

it('queues an inspection per file after a ps2 scan', function () {
    Bus::fake();
    Process::fake();

    File::ensureDirectoryExists($this->root.'/ps2/DVD');
    File::put($this->root.'/ps2/DVD/Castlevania.iso', 'x');
    ConsoleSourceFolder::add(new Console('ps2'), null, 'opl');

    (new ScanConsoleFolder('ps2'))->handle(app(LibraryScanner::class));

    Bus::assertDispatched(InspectGameFile::class, 1);
});

/**
 * What a toolbox opens is declared in the console's config file beside
 * file_extensions and bios_extensions, not in the class. The last case is the
 * one that matters: change the config and the answer changes with it.
 */
it('opens what the console config says it opens', function (string $extension, bool $handled) {
    $file = new GameFile(['extension' => $extension]);

    expect(ps2Tools()->handles($file))->toBe($handled);
})->with([
    ['iso', true],
    ['bin', true],
    ['img', true],
    ['ISO', true],
    // Compressed containers. They are ps2 files — file_extensions lists them —
    // and still nothing can be read inside one, which is why the toolbox keeps
    // a list of its own.
    ['chd', false],
    ['cso', false],
    ['zso', false],
    // Not a ps2 file at all.
    ['cue', false],
]);

it('follows the config rather than a copy of it', function () {
    $bin = new GameFile(['extension' => 'bin']);

    expect(ps2Tools()->handles($bin))->toBeTrue();

    config()->set('consoles.ps2.toolbox_file_extensions', ['iso']);

    // Resolved again, not reused: the toolbox holds the Console it was built
    // with, so the snapshot is per-instance and a config change is picked up
    // by the next caller rather than by the one already holding one.
    expect(ps2Tools()->handles($bin))->toBeFalse();
});

it('opens nothing for a console that declares nothing', function () {
    config()->set('consoles.ps2.toolbox_file_extensions', []);

    expect(ps2Tools()->handles(new GameFile(['extension' => 'iso'])))->toBeFalse();
});

/**
 * The chain carries per-call state, which is the one thing that could leak
 * between two callers in a request. for() clones, so it cannot.
 */
it('gives each caller a toolbox of its own', function () {
    ConsoleSourceFolder::add(new Console('ps2'), null, 'opl');

    $forceful = ps2Tools()->export('cfg')->force();
    $ordinary = ps2Tools()->export('cfg');

    expect($forceful)->not->toBe($ordinary);

    // Nothing to write for either — no games — but the flag being separate is
    // the assertion, and a shared instance would have handed the second one
    // the first one's export and force.
    expect($ordinary->run())->toBe(['written' => 0, 'skipped' => 0, 'failed' => 0]);
});

it('knows which console it serves without being told', function () {
    $tools = ps2Tools();

    // The registry in config/console_tools.php already filed this class under
    // 'ps2'. Asking the class to repeat it was one more place to get wrong.
    expect($tools->consoleKey)->toBe('ps2')
        ->and($tools->console->key)->toBe('ps2');
});

it('resolves nothing for a registry entry that is not a toolbox', function () {
    config()->set('console_tools.consoles.ps2', stdClass::class);

    expect(ConsoleTools::for(new Console('ps2')))->toBeNull();
});

it('resolves nothing for a console with no toolbox at all', function () {
    expect(ConsoleTools::for(new Console('snes')))->toBeNull();
});

it('runs the script the config names, from the directory it names', function () {
    Process::fake(['*' => Process::result('license_id'."\t".'SLES_503.86')]);

    config()->set('console_tools.scripts_path', 'app/Scripts');
    config()->set('console_tools.script', 'Inspect.sh');

    $facts = ps2Tools()->inspect(ps2File());

    expect($facts)->toBe(['license_id' => 'SLES_503.86']);

    Process::assertRan(function ($process) {
        return $process->path === base_path('app/Scripts/PS2')
            && $process->command[0] === 'bash'
            && $process->command[1] === 'Inspect.sh';
    });
});

it('offers an export only on a drive arranged for the loader', function () {
    ConsoleSourceFolder::add(new Console('ps2'), null, 'opl');
    expect(ps2Tools()->canExport())->toBeTrue();

    ConsoleSourceFolder::setLayout(new Console('ps2'), 'custom');
    expect(ps2Tools()->canExport())->toBeFalse();
});
