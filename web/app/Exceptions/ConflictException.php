<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The request is well-formed but collides with existing state, e.g. a file of
 * that name already sits in the destination.
 */
final class ConflictException extends DomainException
{
    /**
     * HTTP status this failure is reported as.
     */
    public function status(): int
    {
        return 409;
    }

    /**
     * Stable code the frontend branches on.
     */
    public function code(): string
    {
        return 'conflict';
    }
}
