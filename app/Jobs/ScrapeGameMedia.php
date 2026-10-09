<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\GameUpdated;
use App\Exceptions\ScreenScraper\ApiUnavailable;
use App\Exceptions\ScreenScraper\QuotaExhausted;
use App\Exceptions\ScreenScraper\ScreenScraperException;
use App\Exceptions\ScreenScraper\SoftwareBlacklisted;
use App\Exceptions\ScreenScraper\ThreadLimitReached;
use App\Models\Game;
use App\Models\MediaList;
use App\Services\MediaLibrary;
use App\Services\ScreenScraperService;
use App\Support\LiveUpdates;
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
 * URL. A job dispatched on its own uses the list the last answer left on the
 * game ({@see MediaList}), and asks the provider only when there is none or
 * when $fresh says the list itself is what somebody wants renewed.
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
     * Ask the provider for the list again rather than use the kept one.
     *
     * Declared with its default rather than promoted. A queued job is
     * unserialised, not constructed, so a job queued before this property
     * existed never runs the constructor that would set it — and a promoted
     * readonly one is then left uninitialised and throws on first read, which
     * took every waiting artwork job down on the upgrade that added it.
     */
    public bool $fresh = false;

    /**
     * @param  array<int, array<string, mixed>>|null  $medias  from the match that triggered this
     * @param  string|null  $region  one region by name, instead of the preference chain
     */
    public function __construct(
        public readonly int $gameId,
        public readonly ?array $medias = null,
        public readonly ?string $region = null,
        bool $fresh = false,
    ) {
        $this->fresh = $fresh;
        $this->onQueue('media');
    }

    /**
     * Queue artwork for a whole console.
     *
     * One job per game, on the media queue, so however many workers
     * QUEUE_WORKERS_MEDIA starts share the console between them and nothing
     * waits on the scraper worker. Only games the provider has already named: artwork is fetched
     * by provider id, so a placeholder or an unmatched game has nothing to
     * fetch by — the same rule {@see Game::blockedFromMediaScrape()} states
     * one game at a time.
     *
     * The metadata request is spent only where it buys something. Filling
     * gaps takes every game short of a switched-on type its kept list offers
     * — what a type switched on in Settings needs, not only games with no
     * artwork at all — and costs a lookup only for a game identified before
     * lists were kept. $held is the other job — every game
     * again, with the list itself renewed, to pick up artwork people have
     * uploaded since — and that is one lookup per game, as its confirmation
     * says. The bytes are mostly free on a second run either way, because a
     * media whose checksum we already hold is recognised without being
     * downloaded.
     *
     * @param  bool  $held  include games that already have artwork, and renew their lists
     * @return int how many were queued
     */
    public static function queueForConsole(string $console, bool $held = false): int
    {
        $games = Game::query()
            ->forConsole($console)
            ->whereNotNull('screenscraper_id');

        if ($held) {
            $ids = $games->pluck('id');
        } else {
            $wanted = MediaTypes::enabled();
            $ids = collect();

            $games->with(['media:id,game_id,screenscraper_type', 'mediaList'])
                ->chunkById(500, function ($chunk) use ($ids, $wanted): void {
                    foreach ($chunk as $game) {
                        if ($game->lacksMedia($wanted)) {
                            $ids->push($game->id);
                        }
                    }
                });
        }

        foreach ($ids as $id) {
            self::dispatch($id, fresh: $held);
        }

        return $ids->count();
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
            $medias = $this->medias
                ?? ($this->fresh ? null : $game->mediaList?->medias)
                ?? $this->refetch($provider, $game);
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

        LiveUpdates::game($game->id, GameUpdated::ARTWORK);
    }

    /**
     * The last try has gone. A page waiting on this game hears it now rather
     * than when its timer runs out.
     */
    public function failed(?Throwable $e): void
    {
        LiveUpdates::game($this->gameId, GameUpdated::FAILED);
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
        $medias = is_array($medias) ? $medias : [];

        $game->rememberMediaList($medias);

        return $medias;
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
