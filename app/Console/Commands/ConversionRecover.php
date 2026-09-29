<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Conversion\ConversionRunner;
use App\Exceptions\LibraryPathException;
use App\Models\Conversion;
use App\Support\LibraryPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * After a restart: every conversion still marked running was interrupted.
 *
 * Run by both web entrypoints before any worker starts, when nothing can be
 * running, so a row that says so is a row whose worker died with the
 * container. It is marked failed, with the reason, and what it had written to
 * staging is removed; Retry puts it back. Queued conversions are untouched
 * and run as the worker comes up. The concurrency slots are freed, because a
 * lock a dead worker held would otherwise stand for as long as its timeout.
 */
class ConversionRecover extends Command
{
    protected $signature = 'conversion:recover';

    protected $description = 'Mark conversions interrupted by a restart as failed, and clean up after them';

    public function handle(ConversionRunner $runner, LibraryPath $paths): int
    {
        $interrupted = Conversion::query()
            ->active()
            ->get();

        foreach ($interrupted as $conversion) {
            $runner->abandon($conversion);
        }

        $concurrency = max(1, (int) config('converters.concurrency', 1));

        for ($i = 1; $i <= $concurrency; $i++) {
            Cache::lock('conversion.slot.'.$i)->forceRelease();
        }

        $orphans = $this->removeOrphans($paths);

        $this->components->info(sprintf('%d interrupted conversion(s) marked failed; %d leftover staging folder(s) removed.', $interrupted->count(), $orphans));

        return self::SUCCESS;
    }

    /** Staging folders of conversions that are over or gone. */
    private function removeOrphans(LibraryPath $paths): int
    {
        try {
            $staging = $paths->stagingDirectory();
        } catch (LibraryPathException) {
            return 0;
        }

        // conv-<id> folders, by the conversion they belong to.
        $folders = collect(File::directories($staging))
            ->filter(function (string $directory): bool {
                return Str::startsWith(basename($directory), 'conv-') && ! is_link($directory);
            })
            ->keyBy(function (string $directory): int {
                return (int) Str::after(basename($directory), 'conv-');
            });

        // One query for all of them; a folder whose conversion is gone or
        // over is left from a run that did not clean up after itself.
        $unfinished = Conversion::query()->whereKey($folders->keys())->unfinished()->pluck('id');
        $orphans = $folders->except($unfinished->all());

        $orphans->each(function (string $directory): void {
            File::deleteDirectory($directory);
        });

        return $orphans->count();
    }
}
