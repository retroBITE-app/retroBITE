<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ScreenScraper\ApiUnavailable;
use App\Exceptions\ScreenScraper\QuotaExhausted;
use App\Exceptions\ScreenScraper\ScreenScraperException;
use App\Exceptions\ScreenScraper\SoftwareBlacklisted;
use App\Exceptions\ScreenScraper\ThreadLimitReached;
use App\Models\Game;
use App\Models\MediaTypePreference;
use App\Services\MediaLibrary;
use App\Services\ScreenScraperService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Download the artwork a game is entitled to.
 *
 * The media list is handed over by the match that just ran, so the ordinary
 * path costs no extra metadata request — the answer already contained every
 * URL. A job dispatched on its own fetches the record again.
 *
 * One broken URL does not sink the rest: a type that fails is logged and
 * skipped, because losing a screenshot is not a reason to lose the box art
 * too. A spent quota is different and stops the whole job, since the next
 * type would meet the same wall.
 */
class ScrapeGameMedia implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 3;

    /**
     * @param  array<int, array<string, mixed>>|null  $medias  from the match that triggered this
     */
    public function __construct(
        public readonly int $gameId,
        public readonly ?array $medias = null,
    ) {
        $this->onQueue('media');
    }

    public function handle(ScreenScraperService $provider, MediaLibrary $library): void
    {
        $game = Game::find($this->gameId);

        if ($game === null || $game->screenscraper_id === null) {
            return;
        }

        $wanted = MediaTypePreference::enabledTypes();

        if ($wanted === []) {
            return;
        }

        try {
            $medias = $this->medias ?? $this->refetch($provider, $game);
        } catch (ScreenScraperException $e) {
            $this->backOff($e);

            return;
        }

        $stored = 0;
        $skipped = 0;

        foreach ($medias as $entry) {
            $type = (string) ($entry['type'] ?? '');
            $url = (string) ($entry['url'] ?? '');

            if ($type === '' || $url === '' || ! in_array($type, $wanted, true)) {
                continue;
            }

            // The metadata already told us this file's checksum, so a copy we
            // hold can be recognised without spending a request at all.
            if (isset($entry['md5']) && $game->media()->where('md5', strtolower((string) $entry['md5']))->exists()) {
                $skipped++;

                continue;
            }

            try {
                $fetch = $provider->fetchMedia($url, $entry['md5'] ?? null);
            } catch (ScreenScraperException $e) {
                // Only conditions that apply to the whole account stop the
                // job — the next type would meet the same wall. Anything that
                // is wrong with this one URL is skipped, because losing a
                // screenshot is not a reason to lose the box art too.
                if ($this->appliesToEveryRequest($e)) {
                    $this->backOff($e);

                    return;
                }

                Log::warning('Skipping one media type.', ['game' => $game->id, 'type' => $type, 'reason' => $e->getMessage()]);
                $skipped++;

                continue;
            } catch (Throwable $e) {
                Log::warning('Skipping one media type.', ['game' => $game->id, 'type' => $type, 'reason' => $e->getMessage()]);
                $skipped++;

                continue;
            }

            if ($fetch->contents === null) {
                $skipped++;

                continue;
            }

            if ($library->store($game, $entry, $fetch->contents) !== null) {
                $stored++;
            }
        }

        Log::info('Media scrape finished.', ['game' => $game->id, 'stored' => $stored, 'skipped' => $skipped]);
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws ScreenScraperException
     */
    private function refetch(ScreenScraperService $provider, Game $game): array
    {
        $payload = $provider->fetchById($game->screenscraper_id);

        $medias = $payload['medias'] ?? [];

        return is_array($medias) ? $medias : [];
    }

    /**
     * Whether this failure would repeat for every other type as well.
     */
    private function appliesToEveryRequest(ScreenScraperException $e): bool
    {
        return $e instanceof QuotaExhausted
            || $e instanceof ThreadLimitReached
            || $e instanceof ApiUnavailable
            || $e instanceof SoftwareBlacklisted;
    }

    private function backOff(ScreenScraperException $e): void
    {
        if (! $e->retryable()) {
            $this->fail($e);

            return;
        }

        $this->release($e->retryAfter() ?? 300);
    }
}
