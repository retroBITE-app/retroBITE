<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ScreenScraper\QuotaExhausted;
use App\Exceptions\ScreenScraper\ScreenScraperException;
use App\Models\AppSetting;
use App\Models\Game;
use App\Services\GameMatcher;
use App\Support\Matching\MatchOutcome;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Ask the provider what one game is.
 *
 * Alone on the scraper queue, which runs a single worker: a plain ScreenScraper
 * account is allowed one thread, so a second worker would collect HTTP 429
 * rather than go faster.
 */
class MatchGame implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 120;

    /**
     * Retries are handled by releasing with a delay the provider's own answer
     * tells us to use, not by burning attempts at whatever interval the queue
     * felt like.
     */
    public int $tries = 3;

    public function __construct(
        public readonly int $gameId,
        public readonly bool $withChecksums = false,
    ) {
        $this->onQueue('scraper');
    }

    /**
     * Queue a lookup for every game on a console still waiting for one.
     *
     * One job per game rather than per file: a four-disc set is one game and
     * one question. Games already looked up and not found are left alone —
     * asking again spends the failed-lookup allowance, which is ten times
     * scarcer than the ordinary one, to be told the same thing.
     *
     * @return int how many were queued
     */
    public static function queueAwaiting(string $console): int
    {
        $ids = Game::query()->awaitingLookup()->forConsole($console)->pluck('id');

        foreach ($ids as $id) {
            self::dispatch($id);
        }

        return $ids->count();
    }

    public function handle(GameMatcher $matcher): void
    {
        $game = Game::find($this->gameId);

        if ($game === null) {
            return;
        }

        try {
            $result = $matcher->match($game, $this->withChecksums);
        } catch (ScreenScraperException $e) {
            $this->waitAndRetry($e);

            return;
        }

        if ($result->outcome === MatchOutcome::NeedsChecksums && $result->file !== null) {
            // Hashing happens on the media queue so the scraper worker is not
            // held for the minutes a disc image takes to read, and the lookup
            // resumes by itself once the checksums exist.
            Bus::chain([
                new HashFile($result->file->id),
                new self($this->gameId, withChecksums: true),
            ])->dispatch();

            return;
        }

        if (
            in_array($result->outcome, [MatchOutcome::Matched, MatchOutcome::Merged], true)
            && $result->game !== null
            && AppSetting::enabled(AppSetting::AUTO_QUEUE_MEDIA_SCRAPE, true)
        ) {
            // The list travels with the result, so fetching artwork costs no
            // second metadata request: the answer already held every URL.
            ScrapeGameMedia::dispatch($result->game->id, $result->medias);
        }

        Log::info('Match attempt finished.', [
            'game' => $this->gameId,
            'outcome' => $result->outcome->value,
            'reason' => $result->reason,
        ]);
    }

    /**
     * Put the job back rather than fail it, when the provider says to wait.
     *
     * Not called backOff: PHP matches method names without regard to case, so
     * that collides with the backoff() the queue looks for on every job, and
     * the framework reaching a private method of that name is fatal at dispatch.
     */
    private function waitAndRetry(ScreenScraperException $e): void
    {
        if (! $e->retryable()) {
            Log::error('Match abandoned.', ['game' => $this->gameId, 'reason' => $e->getMessage()]);
            $this->fail($e);

            return;
        }

        $delay = $e->retryAfter() ?? 300;

        // A spent daily quota is not worth three attempts: every game still
        // queued will get the same answer until the allowance resets, so the
        // job goes back without counting against its tries.
        if ($e instanceof QuotaExhausted) {
            Log::warning('Provider quota spent; the queue will resume after it resets.', [
                'game' => $this->gameId,
                'resumes_in_seconds' => $delay,
            ]);
        }

        $this->release($delay);
    }
}
