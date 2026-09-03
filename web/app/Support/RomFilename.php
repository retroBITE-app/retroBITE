<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reads meaning out of ROM filenames — the tag and product-code conventions ROM
 * sets use. Previously split between a controller and the filesystem service.
 */
final class RomFilename
{
    /**
     * A searchable title, stripping the extension, bracketed tag groups
     * ("(Europe)", "[!]"), a leading product code ("SLUS-20576.", "SLES_527.25.")
     * and trailing disc hints.
     */
    public static function toSearchName(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);

        $base = preg_replace('/\s*[\(\[][^\)\]]*[\)\]]/', '', $base) ?? $base;
        $base = preg_replace('/^[A-Z]{3,5}[-_][0-9]{3,5}(\.[0-9]+)?\s*[-.]\s*/', '', $base) ?? $base;
        $base = preg_replace('/\s*[-_]\s*Disc\s*\d+.*$/i', '', $base) ?? $base;

        return trim(Str::of($base)->replaceMatches('/\s+/', ' ')->toString());
    }

    /**
     * The parenthesised tags in a filename, e.g. ["Europe", "En,Fr,De"].
     *
     * @return string[]
     */
    public static function tags(string $filename): array
    {
        preg_match_all('/\(([^)]+)\)/', $filename, $matches);

        return $matches[1] ?? [];
    }

    /**
     * The region key whose match codes appear in this filename, or null.
     */
    public static function resolveRegionKey(string $filename): ?string
    {
        $tags = Collection::make(self::tags($filename));

        return Collection::make(config('regions'))
            ->filter(fn(array $region) => $tags->intersect($region['codes'])->isNotEmpty())
            ->keys()
            ->first();
    }
}
