<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\RetroAchievements\ApiUnavailable;
use App\Exceptions\RetroAchievements\BadCredentials;
use App\Exceptions\RetroAchievements\InvalidRequest;
use App\Exceptions\RetroAchievements\RateLimited;
use App\Exceptions\RetroAchievements\RetroAchievementsException;
use App\Exceptions\RetroAchievements\ServerError;
use App\Models\AppSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The only thing that talks to RetroAchievements.
 *
 * Kept narrow in the same way ScreenScraperService is: every method returns
 * plain arrays, and the difference between "there is no set" and "we never got
 * to ask" is an exception rather than an empty result. Reading the second as
 * the first would mark a library as having no achievements, in silence.
 */
class RetroAchievementsService
{
    private const USER_AGENT = 'retroBITE';

    /** GetGameList is capped server-side; this is the page we ask for. */
    private const PAGE = 500;

    private const THROTTLE_KEY = 'retroachievements.last_request_at';

    /**
     * Every game on a console, with the hashes that identify each one.
     *
     * f=1 restricts it to games that actually have achievements, h=1 adds the
     * hashes. This is the whole reason identification costs no request: the
     * index is fetched a console at a time, ahead of need.
     *
     * @return array<int, array<string, mixed>>
     */
    public function gameList(int $consoleId): array
    {
        return $this->call('API_GetGameList.php', ['i' => $consoleId, 'f' => 1, 'h' => 1]);
    }

    /**
     * One game's full achievement set.
     *
     * f is a filter, not an addition, whatever the documentation's phrasing
     * suggests. Asked with f=5 this endpoint returns the demoted achievements
     * *instead of* the real ones: ActRaiser answers with 66 achievements and
     * 653 points at f=3, and four titles ending "DEMOTED per revision" at f=5.
     *
     * Nor is there a Flags field in the response to tell them apart, so a set
     * fetched at f=5 and stored looks exactly like a real one.
     *
     * Only the official set is fetched. An achievement that is demoted simply
     * stops appearing here, which SyncSet already reads as having left the set
     * — the right answer, and it costs no second request per game.
     *
     * @return array<string, mixed>|null
     */
    public function gameExtended(int $gameId): ?array
    {
        $payload = $this->call('API_GetGameExtended.php', ['i' => $gameId, 'f' => 3]);

        return Arr::get($payload, 'ID') === null ? null : $payload;
    }

    /**
     * One game, merged with one person's progress in it.
     *
     * a=1 is what adds HighestAwardKind and HighestAwardDate. Note that a
     * locked achievement has no DateEarned key at all rather than a null one.
     *
     * @return array<string, mixed>|null
     */
    public function gameInfoAndUserProgress(string $user, int $gameId): ?array
    {
        $payload = $this->call('API_GetGameInfoAndUserProgress.php', [
            'u' => $user,
            'g' => $gameId,
            'a' => 1,
        ]);

        return Arr::get($payload, 'ID') === null ? null : $payload;
    }

    /**
     * Unlocks from the last few minutes.
     *
     * The cheap pulse. Note the shape disagrees with every other endpoint: one
     * row per unlock with a HardcoreMode boolean, rather than two date columns
     * on one achievement. normaliseRecent() puts that right on the way in, so
     * nothing downstream has to know there were ever two shapes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentAchievements(string $user, int $minutes): array
    {
        return $this->call('API_GetUserRecentAchievements.php', ['u' => $user, 'm' => $minutes]);
    }

    /**
     * Every game a person has played, with counts and their highest award.
     *
     * The reconciliation engine: a handful of requests describe a whole
     * library, which is what makes it possible to find the games that have
     * drifted without asking about each one. It does not report earned points,
     * so those are still counted from the unlock rows.
     *
     * @return array{results: array<int, array<string, mixed>>, total: int}
     */
    public function completionProgress(string $user, int $offset = 0): array
    {
        $payload = $this->call('API_GetUserCompletionProgress.php', [
            'u' => $user,
            'c' => self::PAGE,
            'o' => $offset,
        ]);

        return [
            'results' => (array) Arr::get($payload, 'Results', []),
            'total' => (int) Arr::get($payload, 'Total', 0),
        ];
    }

    /**
     * Where a person stands on one game's board.
     *
     * Comes back as an empty array when they have no progress in the game,
     * which means "no rank" and not "something went wrong".
     *
     * @return array<string, mixed>|null
     */
    public function userGameRankAndScore(string $user, int $gameId): ?array
    {
        $payload = $this->call('API_GetUserGameRankAndScore.php', ['u' => $user, 'g' => $gameId]);
        $first = Arr::first($payload);

        return is_array($first) ? $first : null;
    }

    /**
     * Whether RetroAchievements knows this account.
     *
     * Three answers, not two. Null means the question could not be put — the
     * network is down, or there is no key — and a settings form must save
     * anyway in that case rather than refuse on the strength of an outage.
     *
     * Handles its own statuses instead of going through call(), because a 404
     * here is the answer rather than a failure.
     */
    public function userExists(string $username): ?bool
    {
        $key = $this->apiKey();

        if ($key === '' || trim($username) === '') {
            return null;
        }

        $this->throttle();

        $url = rtrim((string) config('retroachievements.endpoint'), '/').'/API_GetUserProfile.php';

        try {
            $response = $this->send($url, ['u' => $username, 'y' => $key]);
        } catch (RetroAchievementsException) {
            return null;
        }

        // 404 with an empty array is a well-formed name nobody holds. 422 is
        // RetroAchievements rejecting the shape of the name itself, which is
        // also a definite no.
        if ($response->status() === 404 || $response->status() === 422) {
            return false;
        }

        if (! $response->successful()) {
            return null;
        }

        return Arr::get($response->json() ?? [], 'User') !== null;
    }

    /**
     * Turn one row of GetUserRecentAchievements into the two-date shape.
     *
     * @param  array<string, mixed>  $row
     * @return array{achievement_id: int, game_id: int, unlocked_at: string|null, unlocked_hardcore_at: string|null}
     */
    public function normaliseRecent(array $row): array
    {
        $date = Arr::get($row, 'Date', Arr::get($row, 'date'));
        $hardcore = (bool) Arr::get($row, 'HardcoreMode', Arr::get($row, 'hardcoreMode', false));

        return [
            'achievement_id' => (int) Arr::get($row, 'AchievementID', Arr::get($row, 'achievementId', 0)),
            'game_id' => (int) Arr::get($row, 'GameID', Arr::get($row, 'gameId', 0)),

            // A hardcore unlock is also a softcore one — the site counts it in
            // both — so the plain date is always written, and the hardcore
            // column only when the row says so.
            'unlocked_at' => is_string($date) ? $date : null,
            'unlocked_hardcore_at' => ($hardcore && is_string($date)) ? $date : null,
        ];
    }

    /**
     * Issue one request and hand back the decoded body.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws RetroAchievementsException
     */
    private function call(string $endpoint, array $query): array
    {
        $key = $this->apiKey();

        if ($key === '') {
            throw new BadCredentials('No RetroAchievements API key is set.');
        }

        $this->throttle();

        $url = rtrim((string) config('retroachievements.endpoint'), '/').'/'.$endpoint;

        return $this->decode($this->send($url, $query + ['y' => $key]));
    }

    /**
     * Whether a key exists at all, from either source.
     *
     * Public because the sidebar asks before it draws anything: "no key
     * anywhere" and "a key, but nothing has run yet" look identical from the
     * outside and mean opposite things.
     */
    public function hasKey(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * The key from Settings, falling back to the environment.
     *
     * The fallback is what lets a headless install work before anybody has
     * signed in to fill the field.
     */
    private function apiKey(): string
    {
        $stored = AppSetting::getSecret(AppSetting::RA_API_KEY);

        // config carries nothing but an empty string now — the key is set in
        // Settings → RetroAchievements. It is consulted only so a test can
        // stand a key up without the database.
        return trim((string) ($stored ?? config('retroachievements.api_key_fallback', '')));
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws ServerError
     */
    private function send(string $url, array $query): Response
    {
        try {
            return Http::withUserAgent(self::USER_AGENT)
                ->connectTimeout((int) config('retroachievements.connect_timeout', 15))
                ->timeout((int) config('retroachievements.timeout', 180))
                ->get($url, $query);
        } catch (ConnectionException $e) {
            // Guzzle puts the whole URL in its connection errors, and the API
            // key rides in the query string.
            throw new ServerError('RetroAchievements unreachable: '.$this->redact($e->getMessage()));
        }
    }

    /**
     * @return array<mixed>
     *
     * @throws RetroAchievementsException
     */
    private function decode(Response $response): array
    {
        $status = $response->status();

        if ($status >= 400) {
            throw $this->classify($status, $response->body());
        }

        $decoded = json_decode($response->body(), true);

        if (! is_array($decoded)) {
            // A 200 we cannot read is an outage wearing a success code, not an
            // empty answer. Returning [] here would delete a console's index.
            throw new ServerError('Unreadable response body.', $status, $this->redact($response->body()));
        }

        return $decoded;
    }

    private function classify(int $status, string $body): RetroAchievementsException
    {
        $body = $this->redact($body);

        return match (true) {
            $status === 401, $status === 403 => new BadCredentials('Rejected: check the API key.', $status, $body),
            $status === 429 => new RateLimited('Rate limited.', $status, $body),
            $status === 400, $status === 422 => new InvalidRequest('Malformed request.', $status, $body),
            $status >= 500 => new ServerError('RetroAchievements returned '.$status.'.', $status, $body),
            default => new ApiUnavailable('RetroAchievements returned '.$status.'.', $status, $body),
        };
    }

    /** Keep the API key out of logs and stored exception bodies. */
    private function redact(string $text): string
    {
        return (string) preg_replace('/([?&]y=)[^&\s]+/i', '$1***', $text);
    }

    /**
     * Wait out the minimum interval since the last request.
     *
     * In the cache and not a property, because consecutive calls are
     * consecutive queue jobs in separate processes.
     */
    private function throttle(): void
    {
        $interval = (float) config('retroachievements.min_interval', 0.5);

        if ($interval <= 0) {
            return;
        }

        $last = Cache::get(self::THROTTLE_KEY);

        if (is_numeric($last)) {
            $wait = $interval - (microtime(true) - (float) $last);

            if ($wait > 0) {
                usleep((int) round($wait * 1_000_000));
            }
        }

        Cache::put(self::THROTTLE_KEY, microtime(true), 60);
    }
}
