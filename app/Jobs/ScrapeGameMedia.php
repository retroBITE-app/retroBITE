<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ScreenScraper\ApiUnavailable;
use App\Exceptions\ScreenScraper\QuotaExhausted;
use App\Exceptions\ScreenScraper\ScreenScraperException;
use App\Exceptions\ScreenScraper\SoftwareBlacklisted;
use App\Exceptions\ScreenScraper\ThreadLimitReached;
use App\Models\Game;
use App\Services\MediaLibrary;
use App\Services\ScreenScraperService;
use App\Support\MediaRegions;
use App\Support\MediaTypes;
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
     * @param  string|null  $region  one region by name, instead of the preference chain
     */
    public function __construct(
        public readonly int $gameId,
        public readonly ?array $medias = null,
        public readonly ?string $region = null,
    ) {
        $this->onQueue('media');
    }

    public function handle(ScreenScraperService $provider, MediaLibrary $library): void
    {
        $game = Game::find($this->gameId);

        if ($game === null || $game->screenscraper_id === null) {
            return;
        }

        $wanted = MediaTypes::enabled();

        if ($wanted === []) {
            return;
        }

        try {
            $medias = $this->medias ?? $this->refetch($provider, $game);
        } catch (ScreenScraperException $e) {
            $this->waitAndRetry($e);

            return;
        }

        // One copy of each type, not every region the provider holds: otherwise
        // the same cover lands four times in four languages and the interface
        // picks between them at random. Somebody who named a region gets that
        // one and only that one — see onlyRegion().
        $medias = $this->region !== null
            ? MediaRegions::onlyRegion($medias, $this->region)
            : MediaRegions::onePerType($medias);

        $stored = 0;
        $skipped = 0;
        /** @var array<string, string> $outcomes */
        $outcomes = [];

        foreach ($medias as $entry) {
            $type = (string) ($entry['type'] ?? '');
            $url = (string) ($entry['url'] ?? '');

            if ($type === '' || $url === '' || ! in_array($type, $wanted, true)) {
                continue;
            }

            // The metadata already told us this file's checksum, so a copy we
            // hold can be recognised without spending a request at all.
            if (isset($entry['md5']) && $game->media()->where('md5', $claimed = strtolower((string) $entry['md5']))->exists()) {
                // Held, so nothing to download — but if it is this type's
                // copy, an older one from before a region change may still be
                // sitting beside it. keepOne() ignores the call otherwise.
                $library->keepOne($game, $type, $entry['region'] ?? null, $claimed);

                $skipped++;

                continue;
            }

            // The checksum of the copy WE hold, if any — never the provider's
            // own. Sending back the md5 it just gave us is a question it always
            // answers MD5OK to, and MD5OK means no bytes: every media with a
            // checksum in the metadata was skipped and nothing was ever stored.
            $held = $game->media()
                ->where('screenscraper_type', $type)
                ->when(isset($entry['region']), fn ($q) => $q->where('region', $entry['region']))
                ->value('md5');

            try {
                $fetch = $provider->fetchMedia($url, $held);
            } catch (ScreenScraperException $e) {
                // Only conditions that apply to the whole account stop the
                // job — the next type would meet the same wall. Anything that
                // is wrong with this one URL is skipped, because losing a
                // screenshot is not a reason to lose the box art too.
                if ($this->appliesToEveryRequest($e)) {
                    $this->waitAndRetry($e);

                    return;
                }

                Log::warning('Skipping one media type.', ['game' => $game->id, 'type' => $type, 'reason' => $e->getMessage()]);
                $outcomes[$type] = 'failed: '.$e->getMessage();
                $skipped++;

                continue;
            } catch (Throwable $e) {
                Log::warning('Skipping one media type.', ['game' => $game->id, 'type' => $type, 'reason' => $e->getMessage()]);
                $outcomes[$type] = 'failed: '.$e->getMessage();
                $skipped++;

                continue;
            }

            if ($fetch->contents === null) {
                $outcomes[$type] = $fetch->unchanged ? 'unchanged' : 'not held by the provider';
                $skipped++;

                continue;
            }

            if ($library->store($game, $entry, $fetch->contents) !== null) {
                $outcomes[$type] = 'stored';
                $stored++;
            } else {
                $outcomes[$type] = 'already held';
                $skipped++;
            }
        }

        // One record per scrape rather than per file: twenty rows a game would
        // bury the thing somebody opens this to find out — which type came
        // back with what.
        activity('screenscraper')
            ->performedOn($game)
            ->withProperties([
                'endpoint' => 'mediaJeu.php',
                'requested' => $wanted,
                'region' => $this->region,
                'outcomes' => $outcomes,
                'stored' => $stored,
                'skipped' => $skipped,
            ])
            ->log('media scrape');

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
            $this->fail($e);

            return;
        }

        $this->release($e->retryAfter() ?? 300);
    }
}
