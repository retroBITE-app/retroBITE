<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\LibraryFileRejection;
use RuntimeException;

/**
 * A move or a delete in somebody's library was refused.
 *
 * Carries the reason rather than a message, so callers show its label and
 * nothing about paths on the server leaks out.
 */
final class LibraryFileRejected extends RuntimeException
{
    private function __construct(public readonly LibraryFileRejection $reason)
    {
        parent::__construct("Library change rejected: {$reason->value}");
    }

    /**
     * A refusal for this reason.
     */
    public static function because(LibraryFileRejection $reason): self
    {
        return new self($reason);
    }
}
