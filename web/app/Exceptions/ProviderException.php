<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * An upstream metadata provider failed. The message is for the log; clients get
 * the code, because provider errors quote the request URL and its credentials.
 */
final class ProviderException extends DomainException
{
    public function status(): int
    {
        return 502;
    }

    public function code(): string
    {
        return 'provider_unavailable';
    }

    public static function unavailable(string $detail): self
    {
        return new self($detail);
    }

    public static function emptyResult(int $providerId): self
    {
        return new self("Provider returned no result for id {$providerId}");
    }
}
