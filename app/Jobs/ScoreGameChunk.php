<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\GameScorer;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Score one chunk of games: one of the batch `retrobite:score` queues.
 *
 * Reads only the database, so a chunk is seconds. The ranks are worked out
 * once, when the batch is over, not per chunk.
 */
class ScoreGameChunk implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 600;

    /** One attempt: the next run scores whatever this one missed. */
    public int $tries = 1;

    /**
     * @param  list<int>  $gameIds
     */
    public function __construct(
        public readonly array $gameIds,
        public readonly int $number,
        public readonly int $of,
    ) {
        $this->onQueue('default');
    }

    public function handle(GameScorer $scorer): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $scored = $scorer->scoreMany($this->gameIds);

        Log::info('Score chunk finished.', [
            'chunk' => "{$this->number}/{$this->of}",
            'games' => $scored,
        ]);
    }
}
