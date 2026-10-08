<?php

declare(strict_types=1);

namespace App\Decryption;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * What a PS3 disc image says about itself, from a handful of sector reads.
 *
 * A Redump dump keeps its region table in sector 0: alternating plain and
 * encrypted ranges, AES-128-CBC with the disc key and the sector number as
 * the IV. The ISO9660 tree and PARAM.SFO sit in the first plain range, so the
 * game's ID reads the same either way; EBOOT.BIN sits in an encrypted one, and
 * starts with "SCE\0" only once it has been decrypted. That one sector is what
 * tells an encrypted image from a decrypted one, and what proves a key right.
 *
 * ps3dec copies sector 0 unchanged, so a decrypted image still carries its
 * region table: the table says where encryption was, not whether it still is.
 */
final class Ps3Disc
{
    private const SECTOR = 2048;

    /** A decrypted EBOOT.BIN, as a signed executable, starts with this. */
    private const EXECUTABLE = "SCE\0";

    private const EBOOT = 'PS3_GAME/USRDIR/EBOOT.BIN';

    private const PARAM_SFO = 'PS3_GAME/PARAM.SFO';

    /** @var resource */
    private $handle;

    /** @param  resource  $handle */
    private function __construct($handle)
    {
        $this->handle = $handle;
    }

    /** Close the image; one is open only as long as its reader is. */
    public function __destruct()
    {
        fclose($this->handle);
    }

    /** The image at an absolute path, or null when it cannot be read as a disc. */
    public static function open(string $path): ?self
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $handle = @fopen($path, 'rb');

        return $handle === false ? null : new self($handle);
    }

    /**
     * The encrypted sector ranges sector 0 declares, each end exclusive; empty
     * for an image with no region table.
     *
     * @return list<array{start: int, end: int}>
     */
    public function encryptedRanges(): array
    {
        $header = $this->read(0, 1);
        $count = strlen($header) === self::SECTOR ? self::u32be($header, 0) : 0;

        if ($count < 1 || $count > 31) {
            return [];
        }

        $bounds = [];

        for ($i = 0; $i < $count * 2; $i++) {
            $bounds[] = self::u32be($header, 8 + $i * 4);
        }

        $ranges = [];

        for ($i = 1; $i + 1 < count($bounds); $i += 2) {
            if ($bounds[$i + 1] <= $bounds[$i]) {
                return [];
            }

            $ranges[] = ['start' => $bounds[$i] + 1, 'end' => $bounds[$i + 1]];
        }

        return $ranges;
    }

    /** The disc's title ID from PARAM.SFO, e.g. "BLUS30538"; null when there is none to read. */
    public function titleId(): ?string
    {
        $entry = $this->find(self::PARAM_SFO);

        $size = (int) Arr::get($entry ?? [], 'size', 0);

        if ($entry === null || $size < 20) {
            return null;
        }

        $sfo = substr($this->read((int) Arr::get($entry, 'lba'), intdiv($size + self::SECTOR - 1, self::SECTOR)), 0, $size);
        $value = $this->sfoValue($sfo, 'TITLE_ID');

        return $value !== null && Str::isMatch('/^[A-Z]{4}\d{5}$/', $value) ? $value : null;
    }

    /**
     * Whether the encrypted ranges still are; null when it cannot be told (no
     * EBOOT.BIN in an encrypted range). No region table means nothing encrypted.
     */
    public function encrypted(): ?bool
    {
        $ranges = $this->encryptedRanges();

        if ($ranges === []) {
            return $this->find(self::EBOOT) !== null ? false : null;
        }

        $sector = $this->ebootSector($ranges);

        if ($sector === null) {
            return null;
        }

        return ! Str::startsWith($this->read($sector, 1), self::EXECUTABLE);
    }

    /** Whether a disc key decrypts this image's EBOOT.BIN into an executable. */
    public function keyMatches(string $key): bool
    {
        $sector = $this->ebootSector($this->encryptedRanges());
        $hex = DiscKeys::normalise($key);

        if ($sector === null || $hex === null) {
            return false;
        }

        $iv = str_repeat("\0", 12).pack('N', $sector);
        $plain = openssl_decrypt($this->read($sector, 1), 'aes-128-cbc', (string) hex2bin($hex), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);

        return $plain !== false && Str::startsWith($plain, self::EXECUTABLE);
    }

    /**
     * EBOOT.BIN's first sector, when it lies in an encrypted range.
     *
     * @param  list<array{start: int, end: int}>  $ranges
     */
    private function ebootSector(array $ranges): ?int
    {
        $sector = Arr::get($this->find(self::EBOOT) ?? [], 'lba');

        if ($sector === null) {
            return null;
        }

        $inside = collect($ranges)->contains(function (array $range) use ($sector): bool {
            return $sector >= Arr::get($range, 'start') && $sector < Arr::get($range, 'end');
        });

        return $inside ? (int) $sector : null;
    }

    /**
     * A file's extent through the ISO9660 tree, by a path of upper-case names.
     *
     * @return array{lba: int, size: int}|null
     */
    private function find(string $path): ?array
    {
        $volume = $this->read(16, 1);

        if (substr($volume, 1, 5) !== 'CD001') {
            return null;
        }

        $entry = ['lba' => self::u32le($volume, 156 + 2), 'size' => self::u32le($volume, 156 + 10), 'directory' => true];

        foreach (explode('/', $path) as $name) {
            if (! Arr::get($entry, 'directory')) {
                return null;
            }

            $entry = $this->entry((int) Arr::get($entry, 'lba'), (int) Arr::get($entry, 'size'), $name);

            if ($entry === null) {
                return null;
            }
        }

        return ['lba' => (int) Arr::get($entry, 'lba'), 'size' => (int) Arr::get($entry, 'size')];
    }

    /**
     * One named record of a directory. Records never cross a sector, so a zero
     * length means the rest of that sector is padding.
     *
     * @return array{lba: int, size: int, directory: bool}|null
     */
    private function entry(int $lba, int $size, string $name): ?array
    {
        // A directory of a few thousand entries at most; anything bigger is not one.
        $sectors = min(64, intdiv($size + self::SECTOR - 1, self::SECTOR));
        $data = $this->read($lba, $sectors);
        $offset = 0;

        while ($offset < strlen($data)) {
            $length = ord($data[$offset]);

            if ($length === 0) {
                $offset = (intdiv($offset, self::SECTOR) + 1) * self::SECTOR;

                continue;
            }

            $id = substr($data, $offset + 33, ord($data[$offset + 32]));

            if (Str::upper(Str::before($id, ';')) === $name) {
                return [
                    'lba' => self::u32le($data, $offset + 2),
                    'size' => self::u32le($data, $offset + 10),
                    'directory' => (ord($data[$offset + 25]) & 2) === 2,
                ];
            }

            $offset += $length;
        }

        return null;
    }

    /** One string value out of a PARAM.SFO, by key. */
    private function sfoValue(string $sfo, string $key): ?string
    {
        if (! Str::startsWith($sfo, "\0PSF")) {
            return null;
        }

        $keys = self::u32le($sfo, 8);
        $values = self::u32le($sfo, 12);
        $count = self::u32le($sfo, 16);

        for ($i = 0; $i < min($count, 256); $i++) {
            $index = 20 + $i * 16;

            if ($index + 16 > strlen($sfo)) {
                return null;
            }

            $name = Str::before(substr($sfo, $keys + self::u16le($sfo, $index), 64), "\0");

            if ($name === $key) {
                return rtrim(substr($sfo, $values + self::u32le($sfo, $index + 12), self::u32le($sfo, $index + 4)), "\0");
            }
        }

        return null;
    }

    /** Whole sectors from the image; shorter at its end. */
    private function read(int $sector, int $count): string
    {
        if (fseek($this->handle, $sector * self::SECTOR) !== 0) {
            return '';
        }

        $data = fread($this->handle, max(1, $count * self::SECTOR));

        return $data === false ? '' : $data;
    }

    /** A big-endian 32-bit number at an offset; 0 past the end. */
    private static function u32be(string $bytes, int $offset): int
    {
        return strlen($bytes) >= $offset + 4 ? (int) Arr::first((array) unpack('N', $bytes, $offset)) : 0;
    }

    /** A little-endian 32-bit number at an offset, as ISO9660 and PARAM.SFO store them; 0 past the end. */
    private static function u32le(string $bytes, int $offset): int
    {
        return strlen($bytes) >= $offset + 4 ? (int) Arr::first((array) unpack('V', $bytes, $offset)) : 0;
    }

    /** A little-endian 16-bit number at an offset; 0 past the end. */
    private static function u16le(string $bytes, int $offset): int
    {
        return strlen($bytes) >= $offset + 2 ? (int) Arr::first((array) unpack('v', $bytes, $offset)) : 0;
    }
}
