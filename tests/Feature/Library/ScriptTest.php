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

/**
 * A minimal ISO9660 image: a volume descriptor, a root directory, and one
 * file named $name at sector $sector holding $contents.
 *
 * Sparse — written by seeking — so a file placed past the 16 MiB mark costs a
 * few kilobytes on disk rather than sixteen megabytes.
 */
function isoImage(string $filename, int $sector, string $contents, string $name = 'SYSTEM.CNF;1', string $front = ''): string
{
    $path = test()->dir.'/'.$filename;
    $handle = fopen($path, 'w+b');

    // One directory record: length, extent and size each stored both ways
    // round, a date, flags, and the name's length at byte 32.
    $record = function (int $lba, int $size, string $id): string {
        $length = 33 + strlen($id);
        $length += $length % 2;

        return pack('CCVNVNx7CCCvnC', $length, 0, $lba, $lba, $size, $size, 0, 0, 0, 1, 1, strlen($id)).$id.str_repeat("\0", $length - 33 - strlen($id));
    };

    // Anything the search of the first 16 MiB would find before the directory does.
    fwrite($handle, $front);

    // Sector 16: the primary volume descriptor, root directory record at 156.
    fseek($handle, 16 * 2048);
    fwrite($handle, "\x01CD001\x01");
    fseek($handle, 16 * 2048 + 156);
    fwrite($handle, $record(20, 2048, "\0"));

    // Sector 20: the root directory, "." and ".." first as on a real disc.
    fseek($handle, 20 * 2048);
    fwrite($handle, $record(20, 2048, "\0").$record(20, 2048, "\1").$record($sector, strlen($contents), $name));

    fseek($handle, $sector * 2048);
    fwrite($handle, $contents);
    fclose($handle);

    return $path;
}

function inspectImage(string $path): ProcessResult
{
    return Process::path(base_path('app/Scripts/PS2'))
        ->timeout(30)
        ->run(['bash', 'Inspect.sh', $path]);
}

it('finds SYSTEM.CNF through the disc\'s directory, however deep it sits', function () {
    // Past the 16 MiB slice, where the old search had to walk the whole disc
    // — four gigabytes over a network mount, and a job that timed out.
    $path = isoImage('Buffy (Europe).iso', 16 * 512 + 900, "BOOT2 = cdrom0:\\SLES_518.90;1\r\nVER = 1.00\r\nVMODE = PAL\r\n");

    $result = inspectImage($path);

    expect($result->exitCode())->toBe(0)
        ->and(facts($result))->toBe([
            'license_id' => 'SLES_518.90',
            'cover_id' => 'SLES-51890',
            'region' => 'Europe',
            'video_mode' => 'PAL',
        ]);
});

it('believes the directory over a serial lying earlier on the disc', function () {
    // A serial in the first sectors — a demo disc's list, a sequel's advert —
    // is what the slice search would have taken. The directory says which
    // file the console boots, and that is the answer.
    $path = isoImage(
        'Game (Europe).iso',
        16 * 512 + 900,
        "BOOT2 = cdrom0:\\SLES_527.09;1\r\nVMODE = PAL\r\n",
        front: str_repeat("\0", 1024)."BOOT2 = cdrom0:\\SLUS_999.99;1\n",
    );

    expect(facts(inspectImage($path)))->toHaveKey('license_id', 'SLES_527.09');
});

it('reads the name however the disc spells its case', function () {
    $path = isoImage('Game (Europe).iso', 40, "BOOT2 = cdrom0:\\SLES_535.01;1\r\n", name: 'system.cnf;1');

    expect(facts(inspectImage($path)))->toHaveKey('license_id', 'SLES_535.01');
});

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
