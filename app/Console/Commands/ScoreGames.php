<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GameScorer;
use App\Support\Console;
use Illuminate\Console\Command;

/**
 * Work out the retroBite score of every game again (App\Support\RetroBiteScore).
 *
 * Nothing it reads is fetched — the LaunchBox index and the achievement sets
 * are already in the database — so it is cheap, and the index sync runs it
 * after every rebuild. By hand it is for after changing a console's
 * LaunchBox platforms.
 */
class ScoreGames extends Command
{
    protected $signature = 'retrobite:score
                            {--console= : Only games for this console key.}';

    protected $description = 'Work out the retroBite score of every game from the LaunchBox index and RetroAchievements';

    public function handle(GameScorer $scorer): int
    {
        $console = $this->option('console');

        if ($console !== null && ! Console::exists((string) $console)) {
            $this->error("Unknown console: {$console}");

            return self::FAILURE;
        }

        $scored = $scorer->scoreAll($console !== null ? (string) $console : null);

        $this->info("Scored {$scored} games.");

        return self::SUCCESS;
    }
}
