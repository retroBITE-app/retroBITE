<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RecordProviderDumps;
use App\Models\Game;
use Illuminate\Console\Command;

/**
 * Backfill what the provider says about each dump — region, popularity,
 * flags — onto the files of games identified before it was recorded.
 *
 * Not scheduled. A game matched from here on carries them out of the match
 * that identified it; this is a one-off per library, one lookup per game.
 */
class RecordDumps extends Command
{
    protected $signature = 'retrobite:dumps
                            {--console= : Only games for this console key.}
                            {--limit=0 : Stop after this many games.}
                            {--queue : Queue the lookups instead of running them here.}';

    protected $description = 'Record the provider\'s region, popularity and flags for each file of games identified before they were kept';

    public function handle(): int
    {
        $console = $this->option('console') !== null ? (string) $this->option('console') : null;
        $limit = (int) $this->option('limit');

        if ($this->option('queue')) {
            $queued = RecordProviderDumps::queueAwaiting($console, $limit);
            $this->info("Queued {$queued} lookups.");

            return self::SUCCESS;
        }

        $query = Game::query()->awaitingDumps();

        if ($console !== null) {
            $query->forConsole($console);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $ids = $query->pluck('games.id');

        if ($ids->isEmpty()) {
            $this->info('Every identified game has been asked about already.');

            return self::SUCCESS;
        }

        // Said out loud: each is a lookup against the daily allowance.
        $this->line("{$ids->count()} games to ask about.");

        foreach ($ids as $id) {
            dispatch_sync(new RecordProviderDumps((int) $id));
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
