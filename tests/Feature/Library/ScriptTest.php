<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The one test that runs a console's script for real rather than faking the
 * process. Everything else in the suite trusts Inspect.sh to behave; this is
 * what checks that it does, and it is cheap because every case exits in
 * milliseconds.
 *
 * Fixtures are a handful of bytes written here rather than committed. A real
 * PS2 disc is four gigabytes, and none of these cases needs one: `strings`
 * does not care whether the bytes around a BOOT2 line are an ISO9660
 * filesystem or nothing at all.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/retrobite-script-'.Str::random(8);
    File::ensureDirectoryExists($this->dir);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function inspect(string $filename, string $contents): ProcessResult
{
    $path = test()->dir.'/'.$filename;
    File::put($path, $contents);

    return Process::path(base_path('app/Scripts/PS2'))
        ->timeout(30)
        ->run(['bash', 'Inspect.sh', $path]);
}

/** Facts out of stdout, which is tab-separated and nothing else. */
function facts(ProcessResult $result): array
{
    $facts = [];

    foreach (preg_split('/\R/', $result->output()) ?: [] as $line) {
        if (str_contains($line, "\t")) {
            [$key, $value] = explode("\t", $line, 2);
            $facts[$key] = $value;
        }
    }

    return $facts;
}

it('reads a serial out of the disc', function () {
    $result = inspect('Castlevania (Europe).iso', "....BOOT2 = cdrom0:\\SLES_503.86;1\nVMODE = PAL\n....");

    expect($result->exitCode())->toBe(0)
        ->and(facts($result))->toBe([
            'license_id' => 'SLES_503.86',
            'cover_id' => 'SLES-50386',
            'region' => 'Europe',
            'video_mode' => 'PAL',
        ]);
});

it('reads a serial out of the filename without opening the disc', function () {
    // An OPL drive names its files this way, and reading four gigabytes to
    // learn what the filename already said would be a waste of the disk.
    $result = inspect('SLUS_205.76.Harry Potter.iso', '');

    expect($result->exitCode())->toBe(0)
        ->and(facts($result))->toBe([
            'license_id' => 'SLUS_205.76',
            'cover_id' => 'SLUS-20576',
            'region' => 'USA',
        ]);
});

it('refuses a compressed image whatever it is called', function (string $filename, string $magic) {
    $result = inspect($filename, $magic.str_repeat("\0", 64));

    // Exit 1 is "nothing to find here", which is an answer rather than a
    // failure — the toolbox writes no facts and logs nothing alarming.
    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toBe('');
})->with([
    // The case an extension check cannot catch: config says .iso is readable,
    // and this one is a CSO wearing the name.
    'a cso called iso' => ['Game.iso', 'CISO'],
    'a cso' => ['Game.cso', 'CISO'],
    'a zso' => ['Game.zso', 'ZISO'],
    'a chd' => ['Game.chd', 'MComprHD'],
]);

it('says it found nothing in a file with no serial', function () {
    $result = inspect('Homebrew.iso', str_repeat('nothing of interest here. ', 100));

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toBe('');
});

it('refuses to be called wrongly', function () {
    $noArgument = Process::path(base_path('app/Scripts/PS2'))->run(['bash', 'Inspect.sh']);
    $missing = Process::path(base_path('app/Scripts/PS2'))->run(['bash', 'Inspect.sh', $this->dir.'/nope.iso']);

    // Distinct from exit 1: this is the script being used wrongly, not the
    // disc having nothing to say.
    expect($noArgument->exitCode())->toBe(2)
        ->and($missing->exitCode())->toBe(2);
});

it('keeps its complaints off stdout', function () {
    $result = inspect('Homebrew.iso', 'nothing here');

    // The contract the toolbox parses against: stdout is data, and only data.
    expect($result->output())->toBe('')
        ->and($result->errorOutput())->toContain('No PS2 serial');
});
