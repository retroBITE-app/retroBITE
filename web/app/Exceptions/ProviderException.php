<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * An upstream metadata provider failed. The message is for the log; clients get
 * the code, because provider errors quote the request URL and its credentials.
 */
final class ProviderException extends DomainException
{
    /**
     * HTTP status this failure is reported as.
     */
    public function status(): int
    {
        return 502;
    }

    /**
     * Stable code the frontend branches on.
     */
    public function code(): string
    {
        return 'provider_unavailable';
    }

    /**
     * The provider could not be reached or answered badly.
     */
    public static function unavailable(string $detail): self
    {
        return new self($detail);
    }

    /**
     * The provider had no record for that id.
     */
    public static function emptyResult(int $providerId): self
    {
        return new self("Provider returned no result for id {$providerId}");
    }
}
