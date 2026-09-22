<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RateGame;
use App\Models\Game;
use App\Services\ScreenScraperService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * Backfill provider ratings onto games identified before ratings existed.
 *
 * Not scheduled. A game matched from here on carries its rating out of the
 * match that identified it, so this is a one-off for a library that was
 * already catalogued — run it once per console and it has nothing left to do.
 */
class RateGames extends Command
{
    protected $signature = 'retrobite:rate
                            {--console= : Only games for this console key.}
                            {--limit=0 : Stop after this many games.}
                            {--queue : Queue the lookups instead of running them here.}';

    protected $description = 'Fetch provider ratings for games identified before ratings existed';

    public function handle(ScreenScraperService $provider): int
    {
        $query = Game::query()->awaitingRating();

        if ($console = $this->option('console')) {
            $query->forConsole((string) $console);
        }

        if (($limit = (int) $this->option('limit')) > 0) {
            $query->limit($limit);
        }

        $games = $query->get();

        if ($games->isEmpty()) {
            $this->info('Every identified game already has a rating.');

            return self::SUCCESS;
        }

        // Said out loud because each of these is a successful lookup against
        // the daily allowance, and a whole library is a number worth seeing
        // before it is spent.
        $this->line("{$games->count()} games to ask about.");

        foreach ($games as $game) {
            if ($this->option('queue')) {
                RateGame::dispatch($game->id);

                continue;
            }

            $rating = Arr::get($provider->fetchById((int) $game->screenscraper_id) ?? [], 'rating');

            if ($rating !== null) {
                $game->update(['rating' => $rating]);
            }

            $this->line(sprintf(
                '%-40s %s',
                mb_strimwidth($game->title, 0, 40, '…'),
                $rating === null ? 'no rating' : $rating.' / 100',
            ));
        }

        if ($this->option('queue')) {
            $this->info("Queued {$games->count()} rating lookups.");
        }

        return self::SUCCESS;
    }
}
