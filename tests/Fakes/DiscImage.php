<?php

declare(strict_types=1);

namespace Tests\Fakes;

/**
 * Disc images small enough to write in a test: a plain ISO9660 tree of named
 * files, and the CSO or ZSO that packs one.
 *
 * The ISO is enough for rcheevos and the toolbox scripts to walk — a volume
 * descriptor at sector 16, one sector per directory, every number stored both
 * ways round — and nothing more.
 */
final class DiscImage
{
    private const SECTOR = 2048;

    /** Where the root directory sits; the sectors between it and the descriptor stay empty. */
    private const ROOT = 20;

    /**
     * An ISO holding these files, by upper-case path: ['PSP_GAME/PARAM.SFO' => '...'].
     *
     * @param  array<string, string>  $files
     */
    public static function iso(array $files): string
    {
        $directories = [''];

        foreach (array_keys($files) as $path) {
            $parts = explode('/', $path);

            for ($depth = 1; $depth < count($parts); $depth++) {
                $directories[] = implode('/', array_slice($parts, 0, $depth));
            }
        }

        $directories = array_values(array_unique($directories));
        $next = self::ROOT;
        $sectors = [];

        foreach ($directories as $directory) {
            $sectors[$directory] = $next++;
        }

        foreach ($files as $path => $contents) {
            $sectors[$path] = $next;
            $next += max(1, intdiv(strlen($contents) + self::SECTOR - 1, self::SECTOR));
        }

        $image = str_repeat("\0", $next * self::SECTOR);
        $image = self::put($image, 16 * self::SECTOR, "\x01CD001\x01");
        $image = self::put($image, 16 * self::SECTOR + 156, self::record(self::ROOT, self::SECTOR, "\0", true));

        foreach ($directories as $directory) {
            $records = self::record($sectors[$directory], self::SECTOR, "\0", true)
                .self::record($sectors[self::parentOf($directory)], self::SECTOR, "\1", true);

            foreach ($directories as $child) {
                if ($child !== '' && self::parentOf($child) === $directory) {
                    $records .= self::record($sectors[$child], self::SECTOR, basename($child), true);
                }
            }

            foreach ($files as $path => $contents) {
                if (self::parentOf($path) === $directory) {
                    $records .= self::record($sectors[$path], strlen($contents), basename($path).';1', false);
                }
            }

            $image = self::put($image, $sectors[$directory] * self::SECTOR, $records);
        }

        foreach ($files as $path => $contents) {
            $image = self::put($image, $sectors[$path] * self::SECTOR, $contents);
        }

        return $image;
    }

    /**
     * An image packed the way maxcso packs one: a CSO v1 with each block
     * deflated (stored where that would not save anything), or a ZSO with every
     * block stored, since PHP has no LZ4 to compress with.
     */
    public static function pack(string $image, string $magic = 'CISO'): string
    {
        $blocks = str_split($image, self::SECTOR);
        $index = [];
        $data = '';
        $offset = 24 + 4 * (count($blocks) + 1);

        foreach ($blocks as $block) {
            $deflated = $magic === 'CISO' ? (string) gzdeflate($block, 9) : $block;
            $stored = strlen($deflated) >= strlen($block);
            $index[] = $offset | ($stored ? 0x80000000 : 0);
            $chunk = $stored ? $block : $deflated;
            $data .= $chunk;
            $offset += strlen($chunk);
        }

        $index[] = $offset;

        return $magic.pack('VPVCCxx', 24, strlen($image), self::SECTOR, 1, 0).pack('V*', ...$index).$data;
    }

    /** The directory a path sits in, '' for the root. */
    private static function parentOf(string $path): string
    {
        $parent = dirname($path);

        return $parent === '.' ? '' : $parent;
    }

    /** One ISO9660 directory record, each number stored both ways round. */
    private static function record(int $lba, int $size, string $id, bool $directory): string
    {
        $length = 33 + strlen($id);
        $length += $length % 2;

        return pack('CCVNVNx7CCCvnC', $length, 0, $lba, $lba, $size, $size, $directory ? 2 : 0, 0, 0, 1, 1, strlen($id))
            .$id.str_repeat("\0", $length - 33 - strlen($id));
    }

    /** The image with bytes written over it at an offset. */
    private static function put(string $image, int $offset, string $bytes): string
    {
        return substr_replace($image, $bytes, $offset, strlen($bytes));
    }
}
