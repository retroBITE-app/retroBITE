<?php

declare(strict_types=1);

namespace App\Support\Layouts;

use App\Support\Console;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * The layouts config ships, and which of them a console offers.
 *
 * Every lookup here answers rather than throws. A layout key that config no
 * longer carries — one removed in a later release, still stored against a
 * console somebody set up a year ago — falls back to the custom layout, which
 * reads everything and so can never lose a file.
 */
final class Layouts
{
    /** What a console gets when it declares nothing. */
    public const FALLBACK = 'custom';

    public static function make(?string $key): ?ConsoleLayout
    {
        if ($key === null || $key === '') {
            return null;
        }

        $class = Arr::get(self::registry(), $key);

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        $layout = app($class);

        return $layout instanceof ConsoleLayout ? $layout : null;
    }

    /**
     * The layouts this console offers, in the order its config declares them.
     *
     * Always at least one: a console declaring nothing, or nothing config
     * recognises, offers the custom layout on its own.
     *
     * @return Collection<int, ConsoleLayout>
     */
    public static function supportedBy(Console $console): Collection
    {
        $layouts = Collection::make($console->layouts)
            ->map(function (string $key): ?ConsoleLayout {
                return self::make($key);
            })
            ->filter()
            ->values();

        if ($layouts->isEmpty()) {
            return Collection::make([self::fallback()]);
        }

        return $layouts;
    }

    /**
     * The layout to assume when nobody has been asked.
     *
     * The console's declared default where it offers one, and the first layout
     * it supports otherwise — never something it does not support, which would
     * make the picker disagree with the scanner.
     */
    public static function default(Console $console): ConsoleLayout
    {
        $supported = self::supportedBy($console);
        $declared = self::make($console->defaultLayout);

        if ($declared !== null && in_array($declared->key(), self::keysFor($console), true)) {
            return $declared;
        }

        return $supported->first() ?? self::fallback();
    }

    /**
     * Whether a console offers that layout, for validating what a form sends.
     */
    public static function supports(Console $console, string $key): bool
    {
        return self::supportedBy($console)
            ->map(function (ConsoleLayout $layout): string {
                return $layout->key();
            })
            ->contains($key);
    }

    /**
     * The keys a console offers, for a Rule::in().
     *
     * @return string[]
     */
    public static function keysFor(Console $console): array
    {
        return self::supportedBy($console)
            ->map(function (ConsoleLayout $layout): string {
                return $layout->key();
            })
            ->all();
    }

    private static function fallback(): ConsoleLayout
    {
        return self::make(self::FALLBACK) ?? app(CustomLayout::class);
    }

    /** @return array<string, string> */
    private static function registry(): array
    {
        return array_filter((array) config('layouts', []), 'is_string');
    }
}
