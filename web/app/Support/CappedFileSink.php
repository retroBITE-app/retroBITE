<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A write target for a streamed download that refuses to exceed a byte cap.
 *
 * Exists so a short or failed write aborts the transfer: coercing it to 0
 * previously let a truncated image be published as a successful cache entry.
 */
final class CappedFileSink
{
    /** Signals curl to abort the transfer. */
    private const ABORT = -1;

    private int $received = 0;

    private ?string $failure = null;

    /**
     * @param resource $handle Open, writable stream.
     */
    public function __construct(
        private $handle,
        private int $maxBytes,
    ) {}

    /**
     * Append a chunk, returning the bytes written or ABORT to stop the transfer.
     */
    public function write(string $chunk): int
    {
        $this->received += strlen($chunk);

        if ($this->received > $this->maxBytes) {
            $this->failure = 'Image exceeds the size limit';

            return self::ABORT;
        }

        $written = fwrite($this->handle, $chunk);

        if ($written !== strlen($chunk)) {
            $this->failure = 'Could not write the downloaded image';

            return self::ABORT;
        }

        return $written;
    }

    /**
     * Bytes written so far.
     */
    public function received(): int
    {
        return $this->received;
    }

    /**
     * Why the transfer was aborted, or null if it was not.
     */
    public function failure(): ?string
    {
        return $this->failure;
    }
}
