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

    /*
     * Where each console's toolbox lives, and what its entry point is called.
     *
     * Relative to the project root, joined with the console's key upper-cased:
     * app/Scripts/PS2/Inspect.sh. One name for all of them, so adding a
     * toolbox later means writing one file at a known path rather than
     * inventing a name and wiring it up. Plain values rather than env(),
     * because these ship inside the image and relocating them would break it.
     */
    'scripts_path' => 'app/Scripts',

    'script' => 'Inspect.sh',

    'consoles' => [
        'ps2' => PS2::class,
    ],
];
