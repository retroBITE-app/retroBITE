<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A filesystem operation was aimed outside the console's own directory.
 */
final class PathOutsideConsoleException extends DomainException
{
    public function status(): int
    {
        return 422;
    }

    public function code(): string
    {
        return 'path_outside_console';
    }

    public static function forPath(string $path): self
    {
        return new self("Path lives outside the console folder: {$path}");
    }
}
