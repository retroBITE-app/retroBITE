<?php

use App\Services\RetroAchievementsHasher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Tests\Fakes\DiscImage;

/**
 * RAHasher on CSO and ZSO, for real: the image's RAHasher carries a CD reader
 * for them (docker/web/ciso), and the hash it gives a packed image has to be
 * the one it gives the ISO the image was packed from, or RetroAchievements
 * will not know the game. maxcso does the packing, so every format it writes
 * is covered, LZ4 included, and discread is held to the same byte-for-byte
 * standard.
 *
 * Skipped where a binary is not installed — outside the container, that is.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-packed-'.Str::random(8);
    File::ensureDirectoryExists($this->root);
    config()->set('retroachievements.hasher_path', 'RAHasher');
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function requireBinaries(string ...$names): void
{
    foreach ($names as $name) {
        if ((new ExecutableFinder)->find($name) === null) {
            test()->markTestSkipped($name.' is not installed');
        }
    }
}

/** The ISO packed by maxcso beside it, in one of maxcso's formats (cso1, cso2, zso). */
function packWithMaxcso(string $iso, string $format): string
{
    $packed = Str::beforeLast($iso, '.').(Str::startsWith($format, 'cso') ? '.cso' : '.zso');

    Process::run(['maxcso', '--format='.$format, $iso, '-o', $packed])->throw();

    return $packed;
}

/** An executable a few blocks long, random so no block compresses to nothing. */
function executable(): string
{
    return "\x7FELF".random_bytes(2048 * 6 + 300);
}

it('hashes a packed PS2 disc as it hashes the ISO it came from', function (string $format) {
    requireBinaries('RAHasher', 'maxcso');

    $iso = $this->root.'/Game.iso';
    File::put($iso, DiscImage::iso([
        'SYSTEM.CNF' => "BOOT2 = cdrom0:\\SLES_518.90;1\r\nVER = 1.00\r\nVMODE = PAL\r\n",
        'SLES_518.90' => executable(),
    ]));

    $hasher = app(RetroAchievementsHasher::class);

    expect($hasher->hash(21, packWithMaxcso($iso, $format)))->toBe($hasher->hash(21, $iso));
})->with(['cso1', 'cso2', 'zso']);

it('hashes a packed PSP disc as it hashes the ISO it came from', function () {
    requireBinaries('RAHasher', 'maxcso');

    $iso = $this->root.'/Game.iso';
    File::put($iso, DiscImage::iso([
        'PSP_GAME/PARAM.SFO' => "\0PSF".random_bytes(400),
        'PSP_GAME/SYSDIR/EBOOT.BIN' => executable(),
    ]));

    $hasher = app(RetroAchievementsHasher::class);

    expect($hasher->hash(41, packWithMaxcso($iso, 'cso1')))->toBe($hasher->hash(41, $iso));
});

it('hashes a CSO renamed to .iso as the CSO it is', function () {
    requireBinaries('RAHasher', 'maxcso');

    $iso = $this->root.'/Game.iso';
    File::put($iso, DiscImage::iso([
        'SYSTEM.CNF' => "BOOT2 = cdrom0:\\SLUS_205.76;1\r\n",
        'SLUS_205.76' => executable(),
    ]));

    $hasher = app(RetroAchievementsHasher::class);
    $expected = $hasher->hash(21, $iso);
    $renamed = $this->root.'/Renamed.iso';
    File::move(packWithMaxcso($iso, 'cso1'), $renamed);

    expect($hasher->hash(21, $renamed))->toBe($expected);
});

it('reads a packed disc back byte for byte with discread', function (string $format) {
    requireBinaries('discread', 'maxcso');

    $iso = $this->root.'/Game.iso';
    File::put($iso, DiscImage::iso(['DATA.BIN' => random_bytes(2048 * 40 + 77).str_repeat("\0", 2048 * 10)]));

    $packed = packWithMaxcso($iso, $format);
    $whole = Process::run(['discread', $packed, '0']);
    $slice = Process::run(['discread', $packed, '5000', '3000']);

    expect($whole->successful())->toBeTrue()
        ->and(md5($whole->output()))->toBe(md5_file($iso))
        ->and($slice->output())->toBe(substr((string) File::get($iso), 5000, 3000));
})->with(['cso1', 'cso2', 'zso']);
