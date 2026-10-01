<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GameFile;
use App\Support\RomRegions;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Backfill the region of files identified before it was recorded.
 *
 * Read off the filenames alone, so it asks nobody and reads no file. A game
 * identified from here on has its files' regions from the provider's answer,
 * which is better than a name; this only fills the ones still empty, and a
 * provider's region recorded since is never replaced.
 */
class FillFileRegions extends Command
{
    protected $signature = 'retrobite:regions
                            {--console= : Only files of games for this console key.}';

    protected $description = 'Record each file\'s region from its name, where none is recorded yet';

    public function handle(): int
    {
        $query = GameFile::query()->whereNull('region');

        if ($console = $this->option('console')) {
            $query->whereHas('game', fn (Builder $games) => $games->forConsole((string) $console));
        }

        $filled = 0;
        $seen = 0;

        $query->chunkById(500, function (Collection $files) use (&$filled, &$seen): void {
            foreach ($files as $file) {
                $seen++;
                $region = RomRegions::fromFilename((string) $file->filename)[0] ?? null;

                if ($region !== null) {
                    $file->update(['region' => $region]);
                    $filled++;
                }
            }
        });

        $this->info("{$filled} of {$seen} files without a region now have one.");

        return self::SUCCESS;
    }
}
