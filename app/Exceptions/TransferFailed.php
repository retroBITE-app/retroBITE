<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\TransferFailure;
use RuntimeException;
use Throwable;

/**
 * A file transfer that was refused before it wrote anything, or undone after.
 *
 * The path is for the log; the person reads the reason's label.
 */
final class TransferFailed extends RuntimeException
{
    private function __construct(
        public readonly TransferFailure $reason,
        public readonly string $path,
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason->value.': '.$path, 0, $previous);
    }

    public static function because(TransferFailure $reason, string $path = '', ?Throwable $previous = null): self
    {
        return new self($reason, $path, $previous);
    }
}
