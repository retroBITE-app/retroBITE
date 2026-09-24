<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\UploadRejection;
use RuntimeException;

/**
 * A ROM upload was refused, at any step.
 *
 * Carries the reason rather than a message, so callers show the reason's own
 * label and nothing about paths on the server leaks out.
 */
final class UploadRejected extends RuntimeException
{
    /**
     * @param  int|null  $received  bytes already staged, so a client that fell out of step can resume from there
     */
    private function __construct(
        public readonly UploadRejection $reason,
        public readonly ?int $received = null,
    ) {
        parent::__construct("Upload rejected: {$reason->value}");
    }

    /**
     * Any refusal that carries nothing but its reason.
     */
    public static function because(UploadRejection $reason): self
    {
        return new self($reason);
    }

    /**
     * A chunk arrived for an offset other than the end of what is staged.
     */
    public static function outOfOrder(int $received): self
    {
        return new self(UploadRejection::OutOfOrder, $received);
    }
}
