<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PruneGame;
use App\Models\Game;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Queue a prune for every game worth asking about: those with no ROMs at all, and those with one marked missing.
 *
 * Finds them and nothing more — each game's own PruneGame decides what goes.
 * A game with every file still on the disk is never queued, which is nearly
 * all of a healthy library. The games are read a page at a time, so a
 * library of many thousands is never held in memory at once. Run every night
 * by the schedule when Settings → Library has it on, and by the Prune now
 * button there.
 */
class PruneLibrary extends Command
{
    protected $signature = 'retrobite:library:prune
                            {--days= : Days a ROM must have been missing. Defaults to the Settings → Library value.}';

    protected $description = 'Queue a prune for every game with no ROMs, or with ROMs marked missing';

    /** Games read per query. */
    private const CHUNK = 500;

    public function handle(): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : PruneGame::savedDays();

        $withoutFiles = $this->queue(PruneGame::withoutFiles(), $days);
        $withMissing = $this->queue(PruneGame::withMissingFiles(), $days);

        $this->info("Queued {$withoutFiles} games with no ROMs and {$withMissing} with ROMs missing, at {$days} days.");

        return self::SUCCESS;
    }

    /**
     * A prune for each of these games.
     *
     * @param  Builder<Game>  $games
     * @return int how many were queued
     */
    private function queue(Builder $games, int $days): int
    {
        $queued = 0;

        foreach ($games->select('id')->lazyById(self::CHUNK) as $game) {
            PruneGame::dispatch($game->id, $days);
            $queued++;
        }

        return $queued;
    }
}
