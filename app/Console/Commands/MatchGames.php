<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\MatchGame;
use App\Models\Game;
use App\Services\GameMatcher;
use Illuminate\Console\Command;

class MatchGames extends Command
{
    protected $signature = 'retrobite:match
                            {--console= : Only games for this console key.}
                            {--limit=0 : Stop after this many games.}
                            {--queue : Queue the lookups instead of running them here.}';

    protected $description = 'Identify games that have not been looked up yet';

    public function handle(GameMatcher $matcher): int
    {
        $query = Game::query()->awaitingLookup();

        if ($console = $this->option('console')) {
            $query->forConsole((string) $console);
        }

        if (($limit = (int) $this->option('limit')) > 0) {
            $query->limit($limit);
        }

        $games = $query->get();

        if ($games->isEmpty()) {
            $this->info('Nothing is waiting to be identified.');

            return self::SUCCESS;
        }

        foreach ($games as $game) {
            if ($this->option('queue')) {
                MatchGame::dispatch($game->id);

                continue;
            }

            $result = $matcher->match($game);

            $this->line(sprintf(
                '%-40s %s%s',
                mb_strimwidth($game->title, 0, 40, '…'),
                $result->outcome->value,
                $result->reason !== null ? " ({$result->reason})" : '',
            ));
        }

        if ($this->option('queue')) {
            $this->info("Queued {$games->count()} lookups.");
        }

        return self::SUCCESS;
    }
}
