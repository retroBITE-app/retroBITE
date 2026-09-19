<?php

declare(strict_types=1);

use App\Tools\ConsoleTool\PS2;

/**
 * The consoles that have a toolbox, and how long one may take.
 *
 * A toolbox reads facts out of a console's own files that no provider carries.
 * Most consoles have none and never will — a SNES cartridge dump says nothing
 * about itself that the filename does not — so absence here is the normal case
 * and every caller degrades to doing nothing.
 */

return [
    /*
     * Seconds one script may run for.
     *
     * Generous because the work is disk-bound and the disk is often a spinning
     * one over USB: reading a 4 GB image end to end to find a serial the header
     * did not carry is minutes, not seconds.
     */
    'timeout' => env('CONSOLE_TOOLS_TIMEOUT', 900),

    'consoles' => [
        'ps2' => PS2::class,
    ],
];
