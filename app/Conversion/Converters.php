<?php

declare(strict_types=1);

namespace App\Conversion;

use App\Support\Console;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The converters config ships, and which of them a source may be put through.
 *
 * The one compatibility gate: the page offers only what {@see routesFor()}
 * answers, and the job asks again before it runs, so a format a console's
 * frontends cannot read is never written for it — however the request came.
 * A converter with a page of its own, such as PS3 decryption on Tools →
 * Decrypt, passes the same gate; it is only kept out of the Conversion picker.
 */
final class Converters
{
    /** The container key the converters of this request or job are held under. */
    private const INSTANCES = 'conversion.converters';

    public static function make(?string $key): ?Converter
    {
        if ($key === null || $key === '') {
            return null;
        }

        return Arr::get(self::instances(), $key);
    }

    /**
     * Every converter config registers, in its order.
     *
     * @return Collection<int, Converter>
     */
    public static function all(): Collection
    {
        return collect(self::instances())->values();
    }

    /**
     * Whether some conversion on the set's console reads every one of its
     * discs: what puts a set on a source list at all.
     */
    public static function reads(SourceSet $set): bool
    {
        $readable = self::readableOn($set->console);

        return collect($set->extensions())->every(function (string $extension) use ($readable): bool {
            return in_array($extension, $readable, true);
        });
    }

    /**
     * Whether the Conversion picker has anything for this console: a converter
     * that lives on a page of its own does not count.
     */
    public static function offersOn(Console $console): bool
    {
        return self::onPage($console, null)->isNotEmpty();
    }

    /**
     * The extensions some converter on this console can read, for the source list.
     *
     * @return list<string>
     */
    public static function readableOn(Console $console): array
    {
        return array_values(self::onPage($console, null)
            ->flatMap(function (Converter $converter) use ($console): array {
                return array_values(array_filter($converter->from(), function (string $extension) use ($console): bool {
                    return $console->playsExtension($extension);
                }));
            })
            ->unique()
            ->all());
    }

    /**
     * What this set could become, tool installed or not: the console lists
     * the converter in its `converters`, every disc is a format it reads and one the console
     * plays, and the converter takes this particular set.
     *
     * @return Collection<int, Converter>
     */
    public static function candidatesFor(SourceSet $set): Collection
    {
        return self::forConsole($set->console)
            ->filter(function (Converter $converter) use ($set): bool {
                $reads = collect($set->extensions())->every(function (string $extension) use ($converter, $set): bool {
                    return in_array($extension, $converter->from(), true) && $set->console->playsExtension($extension);
                });

                return $reads && $converter->supports($set);
            })
            ->values();
    }

    /**
     * What the Conversion picker offers this set: the candidates, less those
     * that live on a page of their own.
     *
     * @return Collection<int, Converter>
     */
    public static function pickableFor(SourceSet $set): Collection
    {
        return self::candidatesFor($set)
            ->filter(function (Converter $converter): bool {
                return $converter->page() === null;
            })
            ->values();
    }

    /**
     * The console's converters on one Tools page, in its order — what gives the
     * console a tab there. Null is the Conversion picker, as to Tools::onPage().
     *
     * @return Collection<int, Converter>
     */
    public static function onPage(Console $console, ?string $page): Collection
    {
        return self::forConsole($console)
            ->filter(function (Converter $converter) use ($page): bool {
                return $converter->page() === $page;
            })
            ->values();
    }

    /**
     * What this set can become now: the candidates whose tool is installed.
     *
     * @return Collection<int, Converter>
     */
    public static function routesFor(SourceSet $set): Collection
    {
        return self::candidatesFor($set)
            ->filter(function (Converter $converter): bool {
                return Tools::missing($converter) === null;
            })
            ->values();
    }

    /**
     * The converters a console's own file lists, in its order. A key config
     * no longer carries is passed over, as a layout's is.
     *
     * @return Collection<int, Converter>
     */
    private static function forConsole(Console $console): Collection
    {
        return collect([...$console->converters, ...$console->decrypters])
            ->map(function (string $key): ?Converter {
                return self::make($key);
            })
            ->filter()
            ->values();
    }

    /**
     * Every registered converter, by key, made once per request or queue job.
     * They are stateless, and the page asks for them per set, per format and
     * per queue row. A scoped binding rather than a static, as
     * ConsoleSourceFolder::rows() is: flushed between jobs, so a long-lived
     * worker picks up a config change, and new for every test.
     *
     * @return array<string, Converter>
     */
    private static function instances(): array
    {
        if (! app()->bound(self::INSTANCES)) {
            app()->scoped(self::INSTANCES, function (): array {
                return collect(self::registry())
                    ->map(function (string $class, string $key): ?Converter {
                        $converter = class_exists($class) ? app($class) : null;

                        if ($converter !== null && ! $converter instanceof Converter) {
                            Log::warning('A converter is registered that is not a converter.', ['key' => $key, 'class' => $class]);

                            return null;
                        }

                        return $converter;
                    })
                    ->filter()
                    ->all();
            });
        }

        /** @var array<string, Converter> */
        return app(self::INSTANCES);
    }

    /**
     * Converters and decrypters together: both run on this engine, and live
     * in config files of their own (converters.php, decrypters.php).
     *
     * @return array<string, string>
     */
    private static function registry(): array
    {
        return array_filter([
            ...(array) config('converters.converters', []),
            ...(array) config('decrypters.decrypters', []),
        ], 'is_string');
    }
}
