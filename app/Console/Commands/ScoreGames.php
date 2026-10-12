<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ScoreGameChunk;
use App\Models\Game;
use App\Services\GameScorer;
use App\Services\LibraryRanking;
use App\Support\Console;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Work out the retroBite score of every game again (App\Support\RetroBiteScore).
 *
 * Nothing it reads is fetched — the LaunchBox index and the achievement sets
 * are already in the database — but a library of tens of thousands still
 * takes minutes, so by default it is queued: a batch of ScoreGameChunk jobs
 * on `default`, the ranks worked out once when the batch is over. `--now`
 * runs it in the command instead. By hand it is for after changing a
 * console's LaunchBox platforms.
 */
class ScoreGames extends Command
{
    /** Games per job: seconds of work each. */
    public const CHUNK = 250;

    protected $signature = 'retrobite:score
                            {--console= : Only games for this console key.}
                            {--now : Score here and wait, rather than queue it.}';

    protected $description = 'Work out the retroBite score of every game from the LaunchBox index and RetroAchievements';

    public function handle(GameScorer $scorer): int
    {
        $console = $this->option('console');

        if ($console !== null && ! Console::exists((string) $console)) {
            $this->error("Unknown console: {$console}");

            return self::FAILURE;
        }

        $console = $console !== null ? (string) $console : null;

        if ($this->option('now')) {
            $this->info('Scored '.$scorer->scoreAll($console).' games.');

            return self::SUCCESS;
        }

        $chunks = Game::query()
            ->when($console !== null, fn ($query) => $query->forConsole((string) $console))
            ->orderBy('id')
            ->pluck('id')
            ->chunk(self::CHUNK)
            ->map(fn ($ids): array => array_values(array_map('intval', $ids->all())))
            ->values()
            ->all();

        if ($chunks === []) {
            $this->info('No games to score.');

            return self::SUCCESS;
        }

        Bus::batch(array_map(
            fn (array $ids, int $index): ScoreGameChunk => new ScoreGameChunk($ids, $index + 1, count($chunks)),
            $chunks,
            array_keys($chunks),
        ))
            ->name($console !== null ? "Score {$console}" : 'Score library')
            ->allowFailures()
            ->finally(function (): void {
                app(LibraryRanking::class)->rebuild();
            })
            ->onQueue('default')
            ->dispatch();

        $this->info('Queued '.array_sum(array_map('count', $chunks)).' games to score in '.count($chunks).' jobs.');

        return self::SUCCESS;
    }
}
