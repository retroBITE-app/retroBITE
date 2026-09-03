<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A chunked upload could not be staged, assembled or verified.
 */
final class UploadException extends DomainException
{
    public function status(): int
    {
        return 500;
    }

    public function code(): string
    {
        return 'upload_failed';
    }

    public static function incompleteChunks(int $missingIndex): self
    {
        return new self("Upload is incomplete — chunk {$missingIndex} is missing");
    }

    public static function sizeMismatch(int $expected, int $actual): self
    {
        return new self("Assembled size {$actual} does not match the declared {$expected}");
    }
}
