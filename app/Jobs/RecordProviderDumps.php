<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ScreenScraper\QuotaExhausted;
use App\Exceptions\ScreenScraper\ScreenScraperException;
use App\Models\Game;
use App\Services\GameScorer;
use App\Services\ScreenScraperService;
use App\Support\Matching\ProviderDumps;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Record what the provider says about each of one game's dumps: region,
 * popularity, flags (ProviderDumps).
 *
 * A backfill: a game identified from now on has them from the answer that
 * identified it, at no extra cost, and this exists for the library identified
 * before — run a share a night by the scheduler until every game has been
 * asked, and scoring the game again with what it learns. Asks by the provider id the
 * game already holds — not a re-match, which could re-slug or merge the game.
 *
 * On the scraper queue with MatchGame: it spends the same thread allowance.
 */
class RecordProviderDumps implements ShouldQueue
{
    use Queueable;

    /** Well under the default connection's retry_after, so a slow answer is never asked for twice. */
    public int $timeout = 60;

    /**
     * Games asked about a night by the scheduler: a tenth of a plain
     * account's 20 000 a day, so a library of ten thousand is through in a
     * week and the night's matching still has its share.
     */
    public const NIGHTLY = 2000;

    public int $tries = 3;

    public function __construct(public readonly int $gameId)
    {
        $this->onQueue('scraper');
    }

    /**
     * Queue one for every identified game not asked about yet.
     *
     * @return int how many were queued
     */
    public static function queueAwaiting(?string $console = null, int $limit = 0): int
    {
        $query = Game::query()->awaitingDumps();

        if ($console !== null) {
            $query->forConsole($console);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $ids = $query->pluck('games.id');

        foreach ($ids as $id) {
            self::dispatch((int) $id);
        }

        return $ids->count();
    }

    public function handle(ScreenScraperService $provider, GameScorer $scorer): void
    {
        $game = Game::find($this->gameId);

        if ($game === null || $game->screenscraper_id === null) {
            return;
        }

        try {
            $payload = $provider->fetchById($game->screenscraper_id);
        } catch (ScreenScraperException $e) {
            $this->waitAndRetry($e);

            return;
        }

        if ($payload === null) {
            Log::info('The provider holds no game under this id any more.', ['game' => $this->gameId]);

            // Asked, and answered: nothing to record, and nothing to gain by
            // asking again every night.
            $game->forceFill(['provider_scrapes' => null, 'dumps_recorded_at' => now()])->save();

            return;
        }

        ProviderDumps::record($game, $payload);

        // The scrape count is part of the score.
        $scorer->score($game, announce: false);
    }

    /** Put the job back rather than fail it, when the provider says to wait. Named as in MatchGame. */
    private function waitAndRetry(ScreenScraperException $e): void
    {
        if (! $e->retryable()) {
            Log::error('Dump lookup abandoned.', ['game' => $this->gameId, 'reason' => $e->getMessage()]);
            $this->fail($e);

            return;
        }

        $delay = $e->retryAfter() ?? 300;

        if ($e instanceof QuotaExhausted) {
            Log::warning('Provider quota spent; the backfill will resume after it resets.', [
                'game' => $this->gameId,
                'resumes_in_seconds' => $delay,
            ]);
        }

        $this->release($delay);
    }
}
