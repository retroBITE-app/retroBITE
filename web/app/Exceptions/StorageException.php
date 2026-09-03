<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A disk or database write failed in a way the caller should be told about, with
 * the detail kept to the log because it carries absolute host paths.
 */
final class StorageException extends DomainException
{
    /**
     * HTTP status this failure is reported as.
     */
    public function status(): int
    {
        return 500;
    }

    /**
     * Stable code the frontend branches on.
     */
    public function code(): string
    {
        return 'storage_failure';
    }

    /**
     * The move was abandoned and nothing changed.
     */
    public static function moveFailed(): self
    {
        return new self('Move failed');
    }

    /**
     * The file moved but its row still names the old path.
     */
    public static function moveOutOfSync(): self
    {
        return new self('File moved but the database update failed — the record still points at the old path');
    }

    /**
     * The delete could not be completed.
     */
    public static function deleteFailed(): self
    {
        return new self('Delete failed');
    }
}
