<?php

declare(strict_types=1);

namespace App\Support\Scanning;

use RuntimeException;

/**
 * CRC32, MD5 and SHA1 of a file, computed in a single pass.
 *
 * ScreenScraper takes any of the three and the established clients send all
 * three, but a PlayStation 2 image is four gigabytes and a Wii image nearly
 * five. Hashing them one algorithm at a time would read the file three times
 * over; a library of two hundred discs turns that into two and a half
 * terabytes of reading instead of eight hundred gigabytes.
 *
 * Which is also why nothing here runs during a scan. Checksums are computed
 * only when a lookup by name and size has already missed.
 */
final class Checksums
{
    /** Large enough that the syscall overhead disappears, small enough to stay out of memory. */
    private const CHUNK = 1048576;

    public function __construct(
        public readonly string $crc,
        public readonly string $md5,
        public readonly string $sha1,
        public readonly int $size,
    ) {}

    public static function of(string $path): self
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot read {$path} to checksum it.");
        }

        $crc = hash_init('crc32b');
        $md5 = hash_init('md5');
        $sha1 = hash_init('sha1');
        $size = 0;

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, self::CHUNK);

                if ($chunk === false) {
                    throw new RuntimeException("Read failed partway through {$path}.");
                }

                if ($chunk === '') {
                    continue;
                }

                $size += strlen($chunk);
                hash_update($crc, $chunk);
                hash_update($md5, $chunk);
                hash_update($sha1, $chunk);
            }
        } finally {
            fclose($handle);
        }

        return new self(
            // The provider writes CRC32 in upper case throughout its database.
            crc: strtoupper(hash_final($crc)),
            md5: hash_final($md5),
            sha1: hash_final($sha1),
            size: $size,
        );
    }
}
