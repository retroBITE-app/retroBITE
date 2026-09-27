<?php

declare(strict_types=1);

use App\Support\Layouts\CustomLayout;
use App\Support\Layouts\FoldersLayout;
use App\Support\Layouts\OplLayout;
use App\Support\Layouts\RetroArchLayout;

/**
 * Every on-disk arrangement retroBITE can read, keyed by the value stored
 * against a console in console_source_folders.layout.
 *
 * Which of these a console offers is declared in its own config/consoles/ file
 * under `layouts`. A console that declares none is offered `custom` alone,
 * which is what the scanner has always done.
 */

return [
    'custom' => CustomLayout::class,
    'folders' => FoldersLayout::class,
    'opl' => OplLayout::class,
    'retroarch' => RetroArchLayout::class,
];
