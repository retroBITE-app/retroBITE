<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ConversionFailure;
use RuntimeException;
use Throwable;

/**
 * A conversion that stopped short. The detail is for the log; the
 * person reads the reason's label.
 */
final class ConversionFailed extends RuntimeException
{
    private function __construct(
        public readonly ConversionFailure $reason,
        public readonly string $detail,
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason->value.($detail !== '' ? ': '.$detail : ''), 0, $previous);
    }

    public static function because(ConversionFailure $reason, string $detail = '', ?Throwable $previous = null): self
    {
        return new self($reason, $detail, $previous);
    }
}
