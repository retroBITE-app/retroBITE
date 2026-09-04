<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A filesystem operation was aimed outside the console's own directory.
 */
final class PathOutsideConsoleException extends DomainException
{
    /**
     * HTTP status this failure is reported as.
     */
    public function status(): int
    {
        return 422;
    }

    /**
     * Stable code the frontend branches on.
     */
    public function code(): string
    {
        return 'path_outside_console';
    }

    /**
     * A path that resolved outside the console root.
     */
    public static function forPath(string $path): self
    {
        return new self("Path lives outside the console folder: {$path}");
    }
}
