<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The request is well-formed but collides with existing state, e.g. a file of
 * that name already sits in the destination.
 */
final class ConflictException extends DomainException
{
    public function status(): int
    {
        return 409;
    }

    public function code(): string
    {
        return 'conflict';
    }
}
