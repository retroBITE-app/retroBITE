<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RetroAchievements\SyncSet;
use App\Models\Game;
use App\Models\RaGame;
use App\Models\RaProgress;
use App\Services\RetroAchievementsProgress;
use App\Services\RetroAchievementsService;
use Illuminate\Console\Command;

class SyncRetroAchievementsSets extends Command
{
    protected $signature = 'retrobite:ra:sync-sets
                            {--game= : Only this RetroAchievements game id.}
                            {--stale : Only sets with progress rows waiting on a recount.}
                            {--missing : Only sets identified but never downloaded.}
                            {--queue : Queue the syncs instead of running them here.}';

    protected $description = 'Download achievement sets for the identified games';

    public function handle(RetroAchievementsService $provider, RetroAchievementsProgress $progress): int
    {
        $ids = $this->targets();

        if ($ids === []) {
            $this->info('No sets to fetch.');

            return self::SUCCESS;
        }

        // Queued a call's pace apart rather than all at once: thousands of
        // jobs available together were each picked up, found the API's
        // block, and put back, over and over, for every one that ran.
        $interval = max(0.0, (float) config('retroachievements.min_interval', 0.5));

        foreach (array_values($ids) as $index => $id) {
            if ($this->option('queue')) {
                SyncSet::dispatch($id)->delay(now()->addSeconds((int) floor($index * $interval)));

                continue;
            }

            (new SyncSet($id))->handle($provider, $progress);
            $this->line(sprintf('%-10s synced', $id));
        }

        if ($this->option('queue')) {
            $this->info('Queued '.count($ids).' set syncs.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function targets(): array
    {
        if ($game = $this->option('game')) {
            return [(int) $game];
        }

        if ($this->option('stale')) {
            return RaProgress::query()->where('stale', true)->distinct()->pluck('ra_game_id')
                ->map(fn ($id) => (int) $id)->all();
        }

        // Every set a game in the library was identified as. Deliberately not
        // every set in ra_games: the index sync writes a row for every game on
        // a console, and fetching all of those would be thousands of requests
        // for games nobody owns.
        $identified = Game::query()->whereNotNull('retroachievements_id')
            ->distinct()->pluck('retroachievements_id')->map(fn ($id) => (int) $id);

        if ($this->option('missing')) {
            $have = RaGame::query()->whereNotNull('set_synced_at')->pluck('id');

            return $identified->diff($have)->values()->all();
        }

        return $identified->values()->all();
    }
}
