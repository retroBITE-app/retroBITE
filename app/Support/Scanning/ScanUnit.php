<?php

declare(strict_types=1);

namespace App\Support\Scanning;

use App\Enums\FileRole;

/**
 * One game's worth of files, as the scan's plan found them.
 *
 * A playlist with its discs and their tracks, a cuesheet with its tracks, or
 * one loose file. Worked out by LibraryScanner::plan() before anything is
 * written, so the passes that keep a nine-file disc set one game have run
 * over the whole folder before it is cut into chunks; a chunk only records.
 *
 * Files are in the order they are recorded, every parent before its
 * children, so a child finds its parent's row by path.
 */
final class ScanUnit
{
    /**
     * @param  list<array{path: string, size: int|null, role: FileRole, parent: string|null, disc: int|null}>  $files
     */
    public function __construct(
        public readonly string $title,
        public readonly array $files,
    ) {}
}
