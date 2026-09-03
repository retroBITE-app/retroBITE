<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A disk or database write failed in a way the caller should be told about, with
 * the detail kept to the log because it carries absolute host paths.
 */
final class StorageException extends DomainException
{
    public function status(): int
    {
        return 500;
    }

    public function code(): string
    {
        return 'storage_failure';
    }

    public static function moveFailed(): self
    {
        return new self('Move failed');
    }

    public static function moveOutOfSync(): self
    {
        return new self('File moved but the database update failed — the record still points at the old path');
    }

    public static function deleteFailed(): self
    {
        return new self('Delete failed');
    }
}
