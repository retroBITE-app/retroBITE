<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A chunked upload could not be staged, assembled or verified.
 */
final class UploadException extends DomainException
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
        return 'upload_failed';
    }

    /**
     * A chunk the client never sent.
     */
    public static function incompleteChunks(int $missingIndex): self
    {
        return new self("Upload is incomplete — chunk {$missingIndex} is missing");
    }

    /**
     * What landed on disk is not the size the client declared.
     */
    public static function sizeMismatch(int $expected, int $actual): self
    {
        return new self("Assembled size {$actual} does not match the declared {$expected}");
    }
}
