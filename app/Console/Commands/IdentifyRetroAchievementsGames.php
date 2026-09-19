<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RetroAchievementsStatus;
use App\Jobs\RetroAchievements\IdentifyGame;
use App\Models\Game;
use Illuminate\Console\Command;

class IdentifyRetroAchievementsGames extends Command
{
    protected $signature = 'retrobite:ra:identify
                            {--console= : Only games for this console key.}
                            {--limit=0 : Stop after this many games.}
                            {--force : Include games already identified or marked unsupported.}';

    /*
     * No --queue switch, unlike the other library commands. Identification
     * chains a hash job when the cached hash is stale, and that belongs on the
     * hash queue whatever a flag said — running it here would mean hashing
     * disc images in the foreground of somebody's terminal. So it always
     * queues, and the description says so rather than offering a choice that
     * is not really there.
     */
    protected $description = 'Queue RetroAchievements lookups against the local hash index';

    public function handle(): int
    {
        $query = Game::query();

        // Without --force this is Pending and NoMatch only. NoMatch is
        // included where the ScreenScraper equivalent excludes its own
        // failures, because there is no scarce allowance here and new sets
        // appear constantly.
        if (! $this->option('force')) {
            $query->awaitingRetroAchievements();
        }

        if ($console = $this->option('console')) {
            $query->forConsole((string) $console);
        }

        if (($limit = (int) $this->option('limit')) > 0) {
            $query->limit($limit);
        }

        $ids = $query->pluck('games.id');

        if ($ids->isEmpty()) {
            $this->info('Nothing is waiting to be identified.');

            return self::SUCCESS;
        }

        foreach ($ids as $id) {
            IdentifyGame::dispatch($id);
        }

        $this->info("Queued {$ids->count()} RetroAchievements lookups.");
        $this->comment('Run workers on the ra and ra-hash queues to drain them.');

        $this->table(['Status', 'Games'], Game::query()
            ->selectRaw('retroachievements_status, count(*) as games')
            ->groupBy('retroachievements_status')
            ->pluck('games', 'retroachievements_status')
            ->map(fn ($count, $status) => [
                RetroAchievementsStatus::tryFrom((string) $status)?->label() ?? $status,
                $count,
            ])
            ->values()
            ->all());

        return self::SUCCESS;
    }
}
