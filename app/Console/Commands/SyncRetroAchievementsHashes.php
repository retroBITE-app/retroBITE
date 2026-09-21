<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RetroAchievements\SyncHashIndex;
use App\Services\RetroAchievementsMatcher;
use App\Services\RetroAchievementsService;
use App\Support\Console;
use App\Support\RetroAchievements\LibraryConsoles;
use Illuminate\Console\Command;

class SyncRetroAchievementsHashes extends Command
{
    protected $signature = 'retrobite:ra:sync-hashes
                            {console? : Only this console key. Every console in the library otherwise.}
                            {--queue : Queue the syncs instead of running them here.}';

    protected $description = 'Download the RetroAchievements hash index for the consoles in the library';

    public function handle(RetroAchievementsService $provider, RetroAchievementsMatcher $matcher): int
    {
        $targets = $this->targets();

        if ($targets === []) {
            $this->warn('No console in the library is mapped to RetroAchievements.');

            return self::SUCCESS;
        }

        foreach ($targets as $raConsoleId) {
            if ($this->option('queue')) {
                SyncHashIndex::dispatch($raConsoleId);

                continue;
            }

            (new SyncHashIndex($raConsoleId))->handle($provider, $matcher);

            $this->line(sprintf('%-6s synced', $raConsoleId));
        }

        if ($this->option('queue')) {
            $this->info('Queued '.count($targets).' index syncs.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function targets(): array
    {
        $key = $this->argument('console');

        if ($key === null) {
            // Only consoles somebody actually owns games for. A console index
            // is megabytes, and there are 135 consoles.
            return LibraryConsoles::mapped()->keys()->map(fn ($id) => (int) $id)->all();
        }

        $console = Console::tryFrom((string) $key);

        if ($console === null) {
            $this->error("Unknown console: {$key}");

            return [];
        }

        if ($console->retroachievementsId === null) {
            $this->warn("{$console->name} is not on RetroAchievements.");

            return [];
        }

        return [$console->retroachievementsId];
    }
}
