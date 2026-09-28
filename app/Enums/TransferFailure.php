<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a file transfer was refused or stopped.
 *
 * Owns the sentence the person reads, and whether asking again could help:
 * a share that did not answer may answer in a minute, a name already taken
 * will still be taken.
 */
enum TransferFailure: string
{
    case SourceMissing = 'source_missing';
    case Exists = 'exists';
    case Unwritable = 'unwritable';
    case NoSpace = 'no_space';
    case Unreachable = 'unreachable';
    case Denied = 'denied';
    case Incomplete = 'incomplete';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::SourceMissing => __('A file to be sent is missing from the library.'),
            self::Exists => __('A different file with the same name is already there. Nothing was written.'),
            self::Unwritable => __('The destination cannot be written to. Nothing was written.'),
            self::NoSpace => __('There is not enough free space at the destination.'),
            self::Unreachable => __('The destination did not answer.'),
            self::Denied => __('The destination refused the username or password.'),
            self::Incomplete => __('A file did not arrive whole, so the transfer was undone.'),
            self::Rejected => __('The destination refused the transfer.'),
        };
    }

    /** Whether the same transfer, tried again later, might go through. */
    public function retryable(): bool
    {
        return in_array($this, [self::Unreachable, self::Incomplete], true);
    }
}
