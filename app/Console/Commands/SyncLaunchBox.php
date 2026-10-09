<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GameScorer;
use App\Services\LaunchBoxIndex;
use Illuminate\Console\Command;

/**
 * Download the LaunchBox Games Database and rebuild the ratings index from it,
 * then score the library against the new index.
 *
 * Scheduled weekly in routes/console.php, and hourly until it has worked once,
 * so a new install has scores the same day. Not a queued job: it is one
 * download a week, and no page waits on it.
 */
class SyncLaunchBox extends Command
{
    protected $signature = 'retrobite:launchbox:sync';

    protected $description = 'Download the LaunchBox Games Database ratings and score the library against them';

    public function handle(LaunchBoxIndex $index, GameScorer $scorer): int
    {
        $this->line('Downloading '.config('launchbox.metadata_url').' …');

        $result = $index->sync();

        $this->info("Indexed {$result['games']} games and {$result['names']} names on {$result['platforms']} platforms.");

        $scored = $scorer->scoreAll();

        $this->info("Scored {$scored} games.");

        return self::SUCCESS;
    }
}
