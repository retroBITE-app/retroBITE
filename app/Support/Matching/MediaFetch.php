<?php

declare(strict_types=1);

namespace App\Support\Matching;

/**
 * The answer to one media request.
 *
 * Three outcomes, and only one of them is bytes. Asking for a file we already
 * hold returns the word MD5OK instead of the image, and asking for one the
 * provider does not have returns NOMEDIA — both are ordinary answers, not
 * failures, and neither should look like an empty download.
 */
final class MediaFetch
{
    private function __construct(
        public readonly ?string $contents = null,
        public readonly bool $unchanged = false,
        public readonly bool $absent = false,
    ) {}

    public static function downloaded(string $contents): self
    {
        return new self(contents: $contents);
    }

    /** The provider confirmed our copy matches, and sent no bytes. */
    public static function unchanged(): self
    {
        return new self(unchanged: true);
    }

    public static function absent(): self
    {
        return new self(absent: true);
    }
}
