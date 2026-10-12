<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LibraryRanking;
use Illuminate\Console\Command;

/**
 * Work out the retroBite rank of every scored game again (LibraryRanking).
 *
 * Every ten minutes on the schedule with --stale, which does nothing unless
 * a score has moved since the last time.
 */
class RankGames extends Command
{
    protected $signature = 'retrobite:rank
                            {--stale : Only if a score has moved since the ranks were last worked out.}';

    protected $description = 'Work out the retroBite rank of every scored game in the library';

    public function handle(LibraryRanking $ranking): int
    {
        $changed = $this->option('stale') ? $ranking->rebuildIfStale() : $ranking->rebuild();

        if ($changed === null) {
            $this->line('No score has moved; the ranks stand.');

            return self::SUCCESS;
        }

        $this->info("{$changed} games changed place.");

        return self::SUCCESS;
    }
}
