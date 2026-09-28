<?php

declare(strict_types=1);

namespace App\Transfers;

/**
 * One file a transfer writes: where it comes from, where it goes.
 *
 * Both ways to fetch it: a URL for the browser writing a USB drive, and a
 * location for FileTransferJob writing a share.
 */
final class PlannedFile
{
    public function __construct(
        /** A URL the browser can fetch the bytes from. */
        public readonly string $url,
        /** Where the server reads it from. */
        public readonly Location $source,
        /** Relative to the destination's root, with forward slashes, e.g. roms/psx/Game.cue. */
        public readonly string $destination,
        public readonly int $size,
    ) {}

    /** @return array{url: string, destination: string, size: int} */
    public function toArray(): array
    {
        return ['url' => $this->url, 'destination' => $this->destination, 'size' => $this->size];
    }
}
