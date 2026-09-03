<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Base for failures the application understands well enough to answer with a
 * chosen status and a message that is safe to show a client.
 */
abstract class DomainException extends RuntimeException
{
    /**
     * HTTP status this failure should be reported as.
     */
    abstract public function status(): int;

    /**
     * Stable machine-readable code for the frontend to branch on.
     */
    abstract public function code(): string;

    /**
     * Field-level errors, when the failure has any.
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        return [];
    }
}
