<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Arr;

/**
 * Everything the app knows about the provider's regions.
 *
 * The provider answers with every region it holds — a box-2D for Europe, for
 * the United States, for Japan, for the world. Storing all of them fills the
 * disk with the same picture in four languages and leaves the interface
 * picking one arbitrarily. One is chosen here instead, and the same config
 * says how to name and picture the one that was kept.
 */
final class MediaRegions
{
    /**
     * Anything not in the list still works; it simply has no label.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return (array) config('regions.labels', []);
    }

    /**
     * The region's name, or null for a code we hold no label for.
     *
     * The provider can invent codes at any time, so a missing label is normal.
     */
    public static function label(?string $code): ?string
    {
        return $code === null ? null : Arr::get(self::labels(), $code);
    }

    /**
     * The flag for a region shortname, or null when no picture depicts it.
     *
     * Null is an ordinary answer rather than a failure: the interface shows
     * the region's name instead.
     */
    public static function icon(?string $code): ?string
    {
        /** @var array<string, string> $icons */
        $icons = (array) config('regions.icons', []);

        return $code === null ? null : Arr::get($icons, $code);
    }

    /** The region somebody chose, or '' for no preference. */
    public static function preferred(): string
    {
        return (string) AppSetting::get(AppSetting::MEDIA_REGION, '');
    }

    /**
     * Regions in the order they should be tried.
     *
     * @return array<int, string>
     */
    public static function chain(): array
    {
        $fallback = (array) config('regions.fallback', []);
        $preferred = self::preferred();

        if ($preferred === '') {
            return array_values($fallback);
        }

        // The preference leads, and stays out of the tail so it is not tried
        // twice.
        return array_values(array_unique([$preferred, ...$fallback]));
    }

    /**
     * Pick one entry from several copies of the same media type.
     *
     * Region-less media — fanart and video carry no region at all — pass
     * straight through, since there is nothing to choose between.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, mixed>|null
     */
    public static function pick(array $entries): ?array
    {
        if ($entries === []) {
            return null;
        }

        foreach (self::chain() as $region) {
            foreach ($entries as $entry) {
                if (Arr::get($entry, 'region') === $region) {
                    return $entry;
                }
            }
        }

        // Nothing in the chain matched. Better an Italian cover than none.
        return $entries[0];
    }

    /**
     * Reduce a provider media list to one entry per type.
     *
     * @param  array<int, array<string, mixed>>  $medias
     * @return array<int, array<string, mixed>>
     */
    public static function onePerType(array $medias): array
    {
        $byType = [];

        foreach ($medias as $entry) {
            $type = (string) Arr::get($entry, 'type', '');

            if ($type === '') {
                continue;
            }

            $byType[$type][] = $entry;
        }

        return array_values(array_filter(array_map(
            fn (array $entries) => self::pick($entries),
            $byType,
        )));
    }
}
