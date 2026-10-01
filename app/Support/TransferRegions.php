<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AppSetting;

/**
 * The order regions are sent in, when a game holds a version for several.
 *
 * Its own setting rather than the artwork's: the box somebody wants to look
 * at and the copy they want to play are different questions — a Japanese
 * cover on an English game is a taste, a Japanese game on a European
 * console's drive is something that will not run. Until it is set it is the
 * artwork's order, which is what transfers used before it existed. A console
 * can have its own (a console override), and one transfer an order of its
 * own in front of both, sorted in Send to.
 */
final class TransferRegions
{
    /**
     * The library's order.
     *
     * @return list<string>
     */
    public static function order(): array
    {
        $stored = AppSetting::get(AppSetting::TRANSFER_REGIONS);

        if (is_array($stored) && $stored !== []) {
            return self::clean($stored);
        }

        // The provider's neutral entry is artwork's, not a place a dump is from.
        return self::clean(array_filter(MediaRegions::chain(), fn (string $region): bool => $region !== 'ss'));
    }

    /**
     * The console's own order, or the library's for a console without one.
     *
     * @return list<string>
     */
    public static function orderFor(?Console $console): array
    {
        return $console !== null && $console->transferRegions !== []
            ? self::clean($console->transferRegions)
            : self::order();
    }

    /**
     * The order one transfer uses: the one chosen for it in Send to, then
     * the console's for any region it leaves out.
     *
     * @param  list<string>  $chosen
     * @return list<string>
     */
    public static function chainFor(?Console $console, array $chosen = []): array
    {
        return self::clean([...$chosen, ...self::orderFor($console)]);
    }

    /**
     * The regions a dump can be from that an order does not hold yet, by
     * label — what can be added to it. Not the provider's neutral entry,
     * which is artwork's.
     *
     * @param  list<string>  $order
     * @return array<string, string>
     */
    public static function besides(array $order): array
    {
        return array_diff_key(
            array_filter(MediaRegions::labels(), fn (string $label, string $code): bool => $code !== 'ss', ARRAY_FILTER_USE_BOTH),
            array_flip($order),
        );
    }

    /**
     * Keep the library's order.
     *
     * @param  list<string>  $regions
     */
    public static function remember(array $regions): void
    {
        AppSetting::put(AppSetting::TRANSFER_REGIONS, self::clean($regions));
    }

    /**
     * @param  array<array-key, mixed>  $regions
     * @return list<string>
     */
    private static function clean(array $regions): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (mixed $region): string => strtolower(trim((string) $region)), $regions),
            fn (string $region): bool => $region !== '',
        )));
    }
}
