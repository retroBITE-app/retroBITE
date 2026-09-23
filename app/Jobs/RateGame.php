<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ScreenScraper\QuotaExhausted;
use App\Exceptions\ScreenScraper\ScreenScraperException;
use App\Models\Game;
use App\Services\ScreenScraperService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Fetch the provider's rating for one game.
 *
 * Mostly a backfill: new games get their rating from the match that identified
 * them, at no extra cost, and this exists because a library that was already
 * identified would otherwise never show a single one. Asked for by hand from a
 * game's or a console's action menu it is the same job with $force set, which
 * is the only way to ask again about a game that already has one.
 *
 * Deliberately not a re-match. GameMatcher::match() would run the whole
 * identification again, and its apply() can re-slug a game or fold it into
 * another one — consequences far out of proportion to reading one number. This
 * asks by the provider id the game already holds and writes one column.
 *
 * On the scraper queue with MatchGame, because a plain ScreenScraper account is
 * allowed one thread and this spends the same allowance.
 */
class RateGame implements ShouldQueue
{
    use Queueable;

    /** Under the scraper queue's retry_after of 90, which is why it is here. */
    public int $timeout = 60;

    public int $tries = 3;

    /**
     * $force is somebody asking again on purpose.
     *
     * Without it a game that already holds a rating is left alone, which is
     * what makes the backfill safe to run twice. With it the answer is written
     * over the one on the row — ratings are votes and they accumulate, so the
     * second answer is the better one.
     */
    public function __construct(public readonly int $gameId, public readonly bool $force = false)
    {
        $this->onQueue('scraper');
    }

    /**
     * Queue a rating fetch for every identified game on one console.
     *
     * Named and shaped after ScrapeGameMedia::queueForConsole(), because the
     * console's menu offers the two in the same breath and $held means the same
     * thing in both: without it only the gaps are filled, with it every
     * identified game on the console is asked again.
     *
     * @return int how many were queued
     */
    public static function queueForConsole(string $console, bool $held = false): int
    {
        if (! $held) {
            return self::queueAwaiting($console);
        }

        $ids = Game::query()
            ->forConsole($console)
            ->whereNotNull('games.screenscraper_id')
            ->pluck('games.id');

        foreach ($ids as $id) {
            self::dispatch((int) $id, force: true);
        }

        return $ids->count();
    }

    /**
     * Queue a rating fetch for every identified game still without one.
     *
     * @return int how many were queued
     */
    public static function queueAwaiting(?string $console = null, int $limit = 0): int
    {
        $query = Game::query()->awaitingRating();

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

    public function handle(ScreenScraperService $provider): void
    {
        $game = Game::find($this->gameId);

        if ($game === null || $game->screenscraper_id === null) {
            return;
        }

        // The guard that makes the backfill safe to run twice, and the one
        // thing $force is for.
        if ($game->rating !== null && ! $this->force) {
            return;
        }

        try {
            $payload = $provider->fetchById($game->screenscraper_id);
        } catch (ScreenScraperException $e) {
            $this->waitAndRetry($e);

            return;
        }

        // Paid for either way, and the artwork list rides in the same answer.
        // Kept before the rating is read, so a game nobody has voted on still
        // leaves with its list.
        $medias = Arr::get($payload ?? [], 'medias');

        if (is_array($medias)) {
            $game->rememberMediaList($medias);
        }

        $rating = Arr::get($payload ?? [], 'rating');

        if ($rating === null) {
            // Nothing is written and nothing is marked, so the game is picked
            // up by the next run. That is right: a rating is votes, and a game
            // nobody had voted on in March can have a rating in June.
            Log::info('No rating offered for game.', ['game' => $this->gameId]);

            return;
        }

        // One column on the game. Everything else in the payload is already on
        // the row and overwriting it here would make a backfill a
        // re-identification.
        $game->update(['rating' => $rating]);
    }

    /**
     * Put the job back rather than fail it, when the provider says to wait.
     *
     * Named as in MatchGame, and for the same reason: backOff() collides with
     * the backoff() the queue looks for, because PHP matches method names
     * without regard to case.
     */
    private function waitAndRetry(ScreenScraperException $e): void
    {
        if (! $e->retryable()) {
            Log::error('Rating fetch abandoned.', ['game' => $this->gameId, 'reason' => $e->getMessage()]);
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
