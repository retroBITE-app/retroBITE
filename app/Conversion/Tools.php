<?php

declare(strict_types=1);

namespace App\Conversion;

use ArrayObject;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * The command-line tools the converters run, and whether each is here.
 *
 * Asked before anything is offered rather than learned from exit 127 after,
 * so a converter whose tool is missing is simply not offered — the page greys
 * it out and says why — instead of failing an hour into somebody's queue.
 * Finding a binary is a stat() per PATH directory, remembered for the
 * request or queue job it was asked in; asking one for its version starts a
 * process, and is cached for longer.
 */
final class Tools
{
    private const VERSIONS_KEY = 'conversion.tools.versions';

    private const VERSIONS_TTL = 600;

    /** The container key this request's or job's binary lookups are remembered under. */
    private const PATHS = 'conversion.tools.paths';

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::registry());
    }

    /**
     * The absolute path of a tool's binary, or null when it cannot be found
     * or run. A bare name is looked for on PATH; a path is taken as given.
     */
    public static function path(string $tool): ?string
    {
        $paths = self::paths();

        if (! $paths->offsetExists($tool)) {
            $paths->offsetSet($tool, self::find($tool));
        }

        return $paths->offsetGet($tool);
    }

    public static function available(string $tool): bool
    {
        return self::path($tool) !== null;
    }

    /** The first tool a converter needs that is not here, or null when all are. */
    public static function missing(Converter $converter): ?string
    {
        foreach ($converter->tools() as $tool) {
            if (! self::available($tool)) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Whether a tool's exit code means it worked. 0 for nearly all of them;
     * a tool that says otherwise — cue2pops returns 1 on success — declares
     * `success_exit` in config/converters.php.
     */
    public static function succeeded(string $tool, int $exit): bool
    {
        return $exit === (int) Arr::get(self::registry(), $tool.'.success_exit', 0);
    }

    /**
     * Every tool, where it was looked for, where it was found, which version
     * answered, and the conversions it runs — for the Tool paths panel and
     * `conversion:tools`.
     *
     * @return list<array{key: string, label: string, configured: string, path: string|null, pinned: string, found: string|null, licence: string, formats: list<string>}>
     */
    public static function report(): array
    {
        $versions = Cache::remember(self::VERSIONS_KEY, self::VERSIONS_TTL, function (): array {
            return collect(self::keys())
                ->mapWithKeys(function (string $tool): array {
                    return [$tool => self::probe($tool)];
                })
                ->all();
        });

        $formats = self::formatsByTool();

        return array_values(collect(self::registry())
            ->map(function (array $entry, string $tool) use ($versions, $formats): array {
                $found = Arr::get($versions, $tool);

                return [
                    'key' => $tool,
                    'label' => (string) Arr::get($entry, 'label', $tool),
                    'configured' => (string) Arr::get($entry, 'path', ''),
                    'path' => self::path($tool),
                    'pinned' => (string) Arr::get($entry, 'version', ''),
                    'found' => is_string($found) ? $found : null,
                    'licence' => (string) Arr::get($entry, 'licence', ''),
                    'formats' => Arr::get($formats, $tool, []),
                ];
            })
            ->all());
    }

    /** Drop what has been learned about the tools, after a tool path has changed. */
    public static function forget(): void
    {
        Cache::forget(self::VERSIONS_KEY);
        app()->forgetInstance(self::PATHS);
    }

    /**
     * The conversions each tool runs, each as its sources and what it writes,
     * e.g. "CUE/BIN/IMG/ISO → CHD", by tool. A tool no converter uses yet is
     * absent.
     *
     * @return array<string, list<string>>
     */
    private static function formatsByTool(): array
    {
        /** @var array<string, list<string>> */
        return Converters::all()
            ->groupBy(function (Converter $converter): string {
                return $converter->tool();
            })
            ->map(function ($converters): array {
                return $converters
                    ->map(function (Converter $converter): string {
                        return Str::upper(implode('/', $converter->from())).' → '.$converter->label();
                    })
                    ->values()
                    ->all();
            })
            ->all();
    }

    /**
     * Where a tool's binary is, looked for now. A bare name is looked for on
     * PATH; a path is taken as given.
     */
    private static function find(string $tool): ?string
    {
        $configured = (string) Arr::get(self::registry(), $tool.'.path', '');

        if ($configured === '') {
            return null;
        }

        if (Str::contains($configured, '/')) {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }

        return (new ExecutableFinder)->find($configured);
    }

    /**
     * The lookups made so far in this request or job. A scoped binding, as
     * ConsoleSourceFolder::rows() is: flushed between queue jobs, so a worker
     * notices a tool that has come or gone, and new for every test.
     *
     * @return ArrayObject<string, string|null>
     */
    private static function paths(): ArrayObject
    {
        if (! app()->bound(self::PATHS)) {
            app()->scoped(self::PATHS, function (): ArrayObject {
                return new ArrayObject;
            });
        }

        /** @var ArrayObject<string, string|null> */
        return app(self::PATHS);
    }

    /**
     * The version a tool says it is, or null. Most print it to stderr, and
     * chdman only as the first line of its usage, with a non-zero exit; so
     * both streams are read and the exit code is not.
     */
    private static function probe(string $tool): ?string
    {
        $path = self::path($tool);
        $pattern = (string) Arr::get(self::registry(), $tool.'.pattern', '');

        if ($path === null || $pattern === '') {
            return null;
        }

        try {
            $result = Process::timeout(10)->run([$path, ...(array) Arr::get(self::registry(), $tool.'.probe', [])]);
        } catch (Throwable) {
            return null;
        }

        $version = Str::match($pattern, $result->output()."\n".$result->errorOutput());

        return $version !== '' ? $version : null;
    }

    /** @return array<string, array<string, mixed>> */
    private static function registry(): array
    {
        return array_filter((array) config('converters.tools', []), 'is_array');
    }
}
