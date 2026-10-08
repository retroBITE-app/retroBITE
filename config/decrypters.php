<?php

declare(strict_types=1);

use App\Decryption\Ps3Decrypt;

/**
 * Decryption: Tools → Decrypt's decrypters and the tools they run.
 *
 * Kept apart from config/converters.php because a decrypter is not a format
 * conversion — it writes the same format back, readable without a key. It
 * runs on the same engine all the same (App\Conversion: the queue, the
 * runner, staging, progress and cancel), so App\Conversion\Converters and
 * App\Conversion\Tools read this file beside that one, and the concurrency
 * and timeout set there cover both.
 *
 * A console offers one by listing its key under `decrypters` in its
 * config/consoles/ file.
 */
return [

    'decrypters' => [
        'ps3-decrypt' => Ps3Decrypt::class,
    ],

    'tools' => [
        // Redump PS3 ISOs, given their disc key. Built from a pinned tag in
        // the web Dockerfiles' ps3dec stage.
        'ps3dec' => [
            'label' => 'ps3dec',
            'path' => env('PS3DEC_PATH') ?: 'ps3dec',
            'version' => '3.0.0',
            'probe' => ['--version'],
            'pattern' => '/ps3decrs (\S+)/',
            'licence' => 'MIT',
        ],
    ],
];
