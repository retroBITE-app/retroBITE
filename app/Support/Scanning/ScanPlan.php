<?php

declare(strict_types=1);

namespace App\Support\Scanning;

use Illuminate\Support\Str;

/**
 * What a scan will record, worked out from one walk of the folder.
 */
final class ScanPlan
{
    /**
     * @param  list<ScanUnit>  $units
     * @param  int  $skipped  files found that this console does not play
     */
    public function __construct(
        public readonly array $units,
        public readonly int $skipped,
    ) {}

    /**
     * Every file the plan records, keyed by path, for telling what has gone.
     *
     * @return array<string, true>
     */
    public function paths(): array
    {
        $paths = [];

        foreach ($this->units as $unit) {
            foreach ($unit->files as $file) {
                $paths[$file['path']] = true;
            }
        }

        return $paths;
    }

    public function fileCount(): int
    {
        return array_sum(array_map(fn (ScanUnit $unit): int => count($unit->files), $this->units));
    }

    /**
     * The units cut into chunks of about $size, for jobs that may run at once.
     *
     * Titles that reduce to the same slug stay in one chunk, even past the
     * size: "Aladdin" and "aladdin" are one slug and the second gets a
     * suffix, and two workers each finding that slug free would both write
     * it and one would hit the unique index.
     *
     * @return list<list<ScanUnit>>
     */
    public function chunks(int $size): array
    {
        $bySlug = [];

        foreach ($this->units as $unit) {
            $bySlug[Str::slug($unit->title) ?: 'game'][] = $unit;
        }

        ksort($bySlug, SORT_STRING);

        $chunks = [];
        $chunk = [];

        foreach ($bySlug as $units) {
            if ($chunk !== [] && count($chunk) + count($units) > $size) {
                $chunks[] = $chunk;
                $chunk = [];
            }

            array_push($chunk, ...$units);
        }

        if ($chunk !== []) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }
}
