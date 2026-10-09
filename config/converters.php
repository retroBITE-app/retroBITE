<?php

declare(strict_types=1);

use App\Conversion\Converters\ChdToCue;
use App\Conversion\Converters\ChdToIso;
use App\Conversion\Converters\CreateCdChd;
use App\Conversion\Converters\CreateDvdChd;
use App\Conversion\Converters\CsoToIso;
use App\Conversion\Converters\CueToVcd;
use App\Conversion\Converters\EcmDecode;
use App\Conversion\Converters\EcmEncode;
use App\Conversion\Converters\IsoToCso;
use App\Conversion\Converters\IsoToZso;
use App\Conversion\Converters\NodToIso;
use App\Conversion\Converters\ToRvz;
use App\Conversion\Converters\ToWbfs;
use App\Conversion\Converters\VcdToCue;

/**
 * Conversion: every format conversion retroBITE can run, and the command-line
 * tools they run.
 *
 * `converters` maps a key — what a queued conversion stores — to its class.
 * Each class is the whole of one conversion: which consoles and which source
 * formats it takes, what it writes, and the command that writes it. A
 * conversion whose key config no longer carries is refused, never guessed at.
 *
 * `tools` are the binaries those converters shell out to. They ship inside the
 * web image, pinned (see Dockerfile.web), and are looked for on PATH unless
 * the environment names another path. A tool that cannot be found disables the
 * converters that need it; nothing else notices. `version` is what the image
 * pins, `probe` the arguments that make the tool print its own version, and
 * `pattern` where in that output the version is. `success_exit` is the exit
 * code that means it worked, for the rare tool whose is not 0.
 *
 * Paths use `?:` rather than env()'s default, for the reason
 * config/retroachievements.php gives: an empty value in .env would otherwise
 * become an empty program name.
 */
return [
    /*
     * How many conversions may run at once. They run on the conversion worker,
     * so more than one also needs QUEUE_WORKERS_CONVERSION raised to match;
     * past the disk's own speed a second one only makes both slower.
     */
    'concurrency' => max(1, (int) (env('CONVERSION_CONCURRENCY') ?: 1)),

    /*
     * Room left over, in bytes, beyond the sources' own size, before a
     * conversion starts writing. Staging is on the library's disk and an
     * output is about as large as its source at most; a disk filled half way
     * through a 25 GB image is full until staging is cleared.
     */
    'free_space_margin' => 1024 ** 3,

    /*
     * Seconds one conversion may take before it is stopped — the whole of it,
     * every disc and every step. A dual-layer PS2 disc read over a network
     * mount is the case to size for. ConversionRunner::timeout() holds it two
     * minutes under the database-long connection's retry_after (7200), which
     * the conversion worker runs on, whatever is set here, so a conversion still
     * running is never handed out twice.
     */
    'timeout' => (int) (env('CONVERSION_TIMEOUT') ?: 7000),

    'tools' => [
        'chdman' => [
            'label' => 'chdman',
            'path' => env('CHDMAN_PATH') ?: 'chdman',
            'version' => '0.289',
            'probe' => [],
            'pattern' => '/manager\s+(\d+\.\d+)/',
            'licence' => 'GPL-2.0-or-later',
        ],
        'maxcso' => [
            'label' => 'maxcso',
            'path' => env('MAXCSO_PATH') ?: 'maxcso',
            'version' => '1.13.0',
            'probe' => ['--version'],
            'pattern' => '/maxcso v(\d+(?:\.\d+)+)/',
            'licence' => 'ISC',
        ],
        'ecm' => [
            'label' => 'ecm',
            'path' => env('ECM_PATH') ?: 'ecm',
            'version' => '1.3.4',
            'probe' => ['--version'],
            'pattern' => '/ecm (\d+(?:\.\d+)+)/i',
            'licence' => 'GPL-2.0',
        ],
        'unecm' => [
            'label' => 'unecm',
            'path' => env('UNECM_PATH') ?: 'unecm',
            'version' => '1.3.4',
            'probe' => ['--version'],
            'pattern' => '/ecm (\d+(?:\.\d+)+)/i',
            'licence' => 'GPL-2.0',
        ],
        // PS1 to POPStarter VCD, and back. cue2pops returns 1 when it has
        // written the VCD and 0 when it has not, the wrong way round.
        'cue2pops' => [
            'label' => 'cue2pops',
            'path' => env('CUE2POPS_PATH') ?: 'cue2pops',
            'version' => '2.0',
            'probe' => [],
            'pattern' => '/conversion tool v(\d+\.\d+)/',
            'success_exit' => 1,
            'licence' => 'None stated (AUR: GPL-2.0)',
        ],
        'pops2cue' => [
            'label' => 'pops2cue',
            'path' => env('POPS2CUE_PATH') ?: 'pops2cue',
            'version' => '1.0',
            'probe' => [],
            'pattern' => '/POPS2CUE v(\d+\.\d+)/',
            'licence' => 'GPL-3.0',
        ],
        // GameCube and Wii: RVZ, WBFS and back to ISO. A static musl binary
        // from the release page; 2.0 is still an alpha, and the only line
        // that writes anything but ISO.
        'nodtool' => [
            'label' => 'nodtool',
            'path' => env('NODTOOL_PATH') ?: 'nodtool',
            'version' => '2.0.0-alpha.12',
            'probe' => ['-V'],
            'pattern' => '/nodtool (\S+)/',
            'licence' => 'MIT OR Apache-2.0',
        ],
        // Installed for the Xbox toolset to come; no converter uses it yet.
        'extract-xiso' => [
            'label' => 'extract-xiso',
            'path' => env('EXTRACT_XISO_PATH') ?: 'extract-xiso',
            'version' => '2.7.1',
            'probe' => ['-h'],
            'pattern' => '/extract-xiso v(\d+(?:\.\d+)+)/',
            'licence' => 'BSD-style',
        ],
    ],

    'converters' => [
        'chd-cd' => CreateCdChd::class,
        'chd-dvd' => CreateDvdChd::class,
        'chd-to-cue' => ChdToCue::class,
        'chd-to-iso' => ChdToIso::class,
        'cso' => IsoToCso::class,
        'zso' => IsoToZso::class,
        'cso-to-iso' => CsoToIso::class,
        'ecm' => EcmEncode::class,
        'unecm' => EcmDecode::class,
        'vcd' => CueToVcd::class,
        'vcd-to-cue' => VcdToCue::class,
        'rvz' => ToRvz::class,
        'wbfs' => ToWbfs::class,
        'nod-to-iso' => NodToIso::class,
    ],
];
