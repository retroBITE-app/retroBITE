<?php

declare(strict_types=1);

namespace App\Support\Scanning;

/**
 * What one pass over a console's folder did.
 */
final class ScanResult
{
    public function __construct(
        public readonly string $console,
        public readonly int $filesSeen = 0,
        public readonly int $gamesCreated = 0,
        public readonly int $filesCreated = 0,
        public readonly int $filesReturned = 0,
        public readonly int $filesMissing = 0,
        public readonly int $filesSkipped = 0,
    ) {}

    public function with(string $counter, int $by = 1): self
    {
        $values = [
            'filesSeen' => $this->filesSeen,
            'gamesCreated' => $this->gamesCreated,
            'filesCreated' => $this->filesCreated,
            'filesReturned' => $this->filesReturned,
            'filesMissing' => $this->filesMissing,
            'filesSkipped' => $this->filesSkipped,
        ];

        $values[$counter] += $by;

        return new self($this->console, ...array_values($values));
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'console' => $this->console,
            'files_seen' => $this->filesSeen,
            'games_created' => $this->gamesCreated,
            'files_created' => $this->filesCreated,
            'files_returned' => $this->filesReturned,
            'files_missing' => $this->filesMissing,
            'files_skipped' => $this->filesSkipped,
        ];
    }
}
