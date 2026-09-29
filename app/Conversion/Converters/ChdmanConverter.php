<?php

declare(strict_types=1);

namespace App\Conversion\Converters;

use App\Conversion\Converter;
use App\Conversion\Setting;
use App\Conversion\SourceSet;
use App\Conversion\Tools;
use App\Support\Console;
use App\Support\LibraryPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * What every chdman conversion shares: its progress line, its verifier, and
 * telling a CD image inside a CHD from a DVD one.
 *
 * chdman prints "Compressing, 45.2% complete..." (or Extracting, or
 * Verifying) to stderr every half second, carriage-return separated, whether
 * or not anybody is watching — so its own figure is the progress.
 */
abstract class ChdmanConverter extends Converter
{
    /** A raw CD sector: 2,048 bytes of data with its sync, header and error correction. */
    protected const RAW_SECTOR = 2352;

    public function tool(): string
    {
        return 'chdman';
    }

    /** chdman's -np: how many cores it compresses on. All of them unless told. */
    protected function processors(): Setting
    {
        return new Setting(
            key: 'processors',
            label: __('Processors (-np)'),
            description: __('How many CPU cores chdman compresses on. Fewer leaves room for everything else on the machine.'),
            default: 'auto',
            choices: ['auto' => __('All (chdman default)'), '1' => '1', '2' => '2', '4' => '4', '8' => '8'],
        );
    }

    /**
     * The -hs and -np flags for the settings as queued.
     *
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    protected function tuning(array $options): array
    {
        $arguments = ['-hs', $this->setting($options, 'hunk')];
        $processors = $this->setting($options, 'processors');

        if ($processors !== 'auto') {
            array_push($arguments, '-np', $processors);
        }

        return $arguments;
    }

    public function progress(string $line): ?float
    {
        return Str::contains($line, '% complete')
            ? self::percentIn($line)
            : null;
    }

    /**
     * Whether a CHD holds a CD or a DVD: 'cd', 'dvd', or null when chdman is
     * missing or cannot read it. Read from its metadata tags — CHT2/CHTR for
     * CD tracks, DVD for a DVD — and remembered per file and modification
     * time, because the page asks on every render: in the cache for a day,
     * and in memory for the rest of the request. Only an answer is kept; a
     * probe that failed is asked again next time rather than for a day.
     */
    protected static function kind(string $absolute): ?string
    {
        $binary = Tools::path('chdman');

        if ($binary === null || ! is_file($absolute)) {
            return null;
        }

        $cache = Cache::memo();
        $key = 'conversion.chd-kind.'.md5($absolute.'|'.filemtime($absolute).'|'.filesize($absolute));
        $kind = $cache->get($key);

        if (in_array($kind, ['cd', 'dvd'], true)) {
            return $kind;
        }

        $kind = self::probe($binary, $absolute);

        if ($kind !== null) {
            $cache->put($key, $kind, 86400);
        }

        return $kind;
    }

    /** Whether a file's size is a whole number of raw CD sectors. */
    protected static function rawSectors(int $bytes): bool
    {
        return $bytes > 0 && $bytes % self::RAW_SECTOR === 0;
    }

    /**
     * Whether a console has only CDs, going by its own config: one that
     * offers no DVD conversion reads no DVDs, so an .iso there is a CD image
     * and a CHD there holds a CD. PS1 today, and any CD system added later
     * without a line of code.
     */
    protected static function cdOnly(Console $console): bool
    {
        return ! in_array('chd-dvd', $console->converters, true);
    }

    /** Whether every disc of the set is a CHD of this kind. */
    protected static function allOfKind(SourceSet $set, string $root, string $kind): bool
    {
        foreach ($set->discs as $disc) {
            if (self::kind($root.'/'.$disc->file->path) !== $kind) {
                return false;
            }
        }

        return true;
    }

    protected static function root(): string
    {
        return app(LibraryPath::class)->root();
    }

    /** What `chdman info` says the CHD holds, or null when it would not say. */
    private static function probe(string $binary, string $absolute): ?string
    {
        try {
            $info = Process::timeout(30)->run([$binary, 'info', '-i', $absolute])->output();
        } catch (Throwable) {
            return null;
        }

        return match (true) {
            Str::contains($info, ["Tag='CHT2'", "Tag='CHTR'", "Tag='CHCD'"]) => 'cd',
            Str::contains($info, ["Tag='DVD ", "Tag='DVD'"]) => 'dvd',
            default => null,
        };
    }
}
