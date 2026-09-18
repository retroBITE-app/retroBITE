<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The scan refused to run because its source folder is not trustworthy.
 *
 * An empty or absent folder is almost never a library somebody emptied on
 * purpose — it is an unmounted disk, a container started before its volume, or
 * a path typed wrong. Treating it as truth would mark every file in that
 * console as missing and, once the disk came back, spend the day's quota
 * identifying a library that was never lost.
 */
final class ScanAborted extends RuntimeException
{
    public static function missingFolder(string $console, string $path): self
    {
        return new self("Source folder for {$console} does not exist: {$path}");
    }

    public static function emptyFolder(string $console, string $path): self
    {
        return new self("Source folder for {$console} is empty: {$path}. Refusing to mark the whole console missing — check the mount.");
    }

    public static function unconfigured(string $console): self
    {
        return new self("No source folder is configured for {$console}.");
    }
}
