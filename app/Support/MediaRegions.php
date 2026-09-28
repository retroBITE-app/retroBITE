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
     * Regions that are not a country: the provider's own default and the
     * world, plus none at all.
     */
    private const NEUTRAL = ['', 'ss', 'wor'];

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
        return self::chainFor(self::preferred());
    }

    /**
     * The same chain, led by a region chosen for one game.
     *
     * A game may name its own — an import whose owner wants the English box —
     * and it leads over the library-wide preference, which in turn leads over
     * the neutral fallbacks. Empty or null at either level simply drops out.
     *
     * @return array<int, string>
     */
    public static function chainFor(?string $preferred): array
    {
        $fallback = (array) config('regions.fallback', []);

        $chain = array_filter([
            (string) $preferred,
            self::preferred(),
        ], fn (string $region) => $region !== '');

        // Each stays out of the tail so none is tried twice.
        return array_values(array_unique([...$chain, ...$fallback]));
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
        return array_values(array_filter(array_map(
            fn (array $entries) => self::pick($entries),
            self::byType($medias),
        )));
    }

    /**
     * One entry per type, from one named region and nowhere else.
     *
     * For somebody who asked for the Japanese artwork by name. No fallback
     * for a type the provider holds per country: one this region has nothing
     * for is left alone rather than fetched from the chain, which would spend
     * a download re-fetching the copy they already have under a label that
     * says Japan.
     *
     * A type the provider holds only for the world — screenshots, title
     * screens, fanart — has no regional variant to choose, so it comes along
     * whatever the region. Leaving it out lost the screenshot of every game
     * fetched by region before screenshots were switched on, and nearly every
     * theme shows one. One already held costs nothing: its checksum is
     * recognised without a download.
     *
     * @param  array<int, array<string, mixed>>  $medias
     * @return array<int, array<string, mixed>>
     */
    public static function onlyRegion(array $medias, string $region): array
    {
        $picked = [];

        foreach (self::byType($medias) as $entries) {
            $chosen = Arr::first($entries, fn (array $entry): bool => Arr::get($entry, 'region') === $region);

            if ($chosen === null && self::onlyNeutral($entries)) {
                $chosen = self::pick($entries);
            }

            if ($chosen !== null) {
                $picked[] = $chosen;
            }
        }

        return $picked;
    }

    /**
     * Whether every copy of a type is region-less or for the whole world.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    private static function onlyNeutral(array $entries): bool
    {
        foreach ($entries as $entry) {
            $region = (string) Arr::get($entry, 'region', '');

            if (! in_array($region, self::NEUTRAL, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The provider's list grouped by media type, in the order it arrived.
     *
     * @param  array<int, array<string, mixed>>  $medias
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function byType(array $medias): array
    {
        $byType = [];

        foreach ($medias as $entry) {
            $type = (string) Arr::get($entry, 'type', '');

            if ($type === '') {
                continue;
            }

            $byType[$type][] = $entry;
        }

        return $byType;
    }
}
