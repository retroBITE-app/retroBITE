<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Submitted input was rejected. Carries per-field messages where it has them.
 */
final class ValidationException extends DomainException
{
    /**
     * @param array<string, string> $fieldErrors
     */
    public function __construct(
        string $message,
        private array $fieldErrors = [],
    ) {
        parent::__construct($message);
    }

    /**
     * HTTP status this failure is reported as.
     */
    public function status(): int
    {
        return 422;
    }

    /**
     * Stable code the frontend branches on.
     */
    public function code(): string
    {
        return 'validation_failed';
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->fieldErrors;
    }

    /**
     * A single-message failure with no field attribution.
     */
    public static function because(string $message): self
    {
        return new self($message);
    }

    /**
     * A failure carrying a field => message bag.
     *
     * @param array<string, string> $errors
     */
    public static function fields(array $errors): self
    {
        return new self('Validation failed', $errors);
    }
}
