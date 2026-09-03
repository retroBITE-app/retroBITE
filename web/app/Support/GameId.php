<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The composite `{console}:{filename}` primary key used by the games table.
 * Previously assembled by hand at seven call sites.
 */
final readonly class GameId
{
    private const SEPARATOR = ':';

    private function __construct(
        public string $console,
        public string $fileName,
    ) {}

    /**
     * Build an id from a console key and a file name.
     */
    public static function make(string $console, string $fileName): self
    {
        return new self($console, $fileName);
    }

    /**
     * Build an id for a game inside a resolved console.
     */
    public static function for(Console $console, ?string $fileName): self
    {
        return new self($console->key, (string) $fileName);
    }

    public function __toString(): string
    {
        return $this->console . self::SEPARATOR . $this->fileName;
    }
}
