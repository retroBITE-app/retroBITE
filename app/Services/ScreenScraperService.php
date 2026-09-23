<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MediaKind;
use App\Exceptions\ScreenScraper\ApiUnavailable;
use App\Exceptions\ScreenScraper\BadCredentials;
use App\Exceptions\ScreenScraper\FailedLookupQuotaExhausted;
use App\Exceptions\ScreenScraper\InvalidRequest;
use App\Exceptions\ScreenScraper\QuotaExhausted;
use App\Exceptions\ScreenScraper\ScreenScraperException;
use App\Exceptions\ScreenScraper\ServerError;
use App\Exceptions\ScreenScraper\SoftwareBlacklisted;
use App\Exceptions\ScreenScraper\ThreadLimitReached;
use App\Support\Console;
use App\Support\Matching\MediaFetch;
use App\Support\ScreenScraperCredentials;
use App\Support\ScreenScraperQuota;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Docs: https://www.screenscraper.fr/webapi2.php
 */
class ScreenScraperService
{
    private const SOFTNAME = 'retroBITE';

    private const DEFAULT_REGION = 'ss';

    private const PREFERRED_LANG = 'en';

    /** Region preference for box art / screenshots — English-speaking first. */
    private const MEDIA_REGION_PRIORITY = ['us', 'wor', 'eu', 'uk', 'au', 'ss'];

    /**
     * Fragments of the plain-text error bodies the API returns.
     *
     * These are not JSON: the response carries `Content-Type: application/json`
     * but the body is a bare Latin-1 French sentence, so the status code is
     * confirmed against the text rather than trusted on its own. Matching is
     * done on accent-free fragments so the encoding cannot break it.
     */
    private const BODY_NOT_FOUND = ['non trouv'];

    private const BODY_QUOTA = ['quota de scrape'];

    private const BODY_FAILED_QUOTA = ['du tri dans vos fichiers'];

    private const BODY_THREADS = ['nombre de threads', 'maximum threads', 'threads allowed'];

    private const BODY_CLOSED = ['api ferm', 'api closed', 'api totalement'];

    private const BODY_BLACKLISTED = ['blacklist'];

    /**
     * "Erreur de login : Verifier les identifiants utilisateurs !"
     *
     * The user pair, not the developer pair — and the status is the same 403
     * either way, so only the body tells them apart. Worth the distinction
     * because the two are fixed in different places: one is in .env, the other
     * ships with the application.
     */
    private const BODY_BAD_USER = ['identifiants utilisateur'];

    /** Tracks the last request so the interval survives between queued jobs. */
    private const THROTTLE_KEY = 'screenscraper.last_request_at';

    /**
     * Identify a game from whatever we know about the file.
     *
     * Accepts any mix of `romnom` (bare filename, never a path), `romtaille`
     * (bytes), `crc`, `md5`, `sha1` and `romtype`. Name and size alone are a
     * valid lookup and cost nothing to compute, which is why the scanner tries
     * them before reading four gigabytes to produce a checksum.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>|null
     *
     * @throws ScreenScraperException
     */
    public function lookup(Console $console, array $criteria): ?array
    {
        if ($console->screenscraperId === null) {
            return null;
        }

        $params = array_filter([
            'systemeid' => $console->screenscraperId,
            'romnom' => $this->bareFilename($criteria['romnom'] ?? null),
            'romtaille' => isset($criteria['romtaille']) ? (int) $criteria['romtaille'] : null,
            'romtype' => $criteria['romtype'] ?? null,
            'crc' => isset($criteria['crc']) ? strtolower((string) $criteria['crc']) : null,
            'md5' => isset($criteria['md5']) ? strtolower((string) $criteria['md5']) : null,
            'sha1' => isset($criteria['sha1']) ? strtolower((string) $criteria['sha1']) : null,
        ], fn (mixed $v) => $v !== null && $v !== '' && $v !== 0);

        // systemeid alone identifies nothing and still spends a request.
        if (count($params) < 2) {
            return null;
        }

        $response = $this->call('jeuInfos.php', $params);

        $jeu = Arr::get($response, 'response.jeu');

        return is_array($jeu) ? $this->normalize($jeu) : null;
    }

    /**
     * Look up a single game by ROM md5 + console system id.
     *
     * @return array<string, mixed>|null
     *
     * @throws ScreenScraperException
     */
    public function lookupByMd5(Console $console, string $md5): ?array
    {
        return $md5 === '' ? null : $this->lookup($console, ['md5' => $md5]);
    }

    /**
     * The filename on its own.
     *
     * A documented 400: `romnom` carrying any path separator is rejected
     * outright, and the scanner naturally holds full paths.
     */
    private function bareFilename(mixed $name): ?string
    {
        if (! is_string($name) || $name === '') {
            return null;
        }

        return basename(str_replace('\\', '/', $name));
    }

    /**
     * Search for candidate matches by name + console system id.
     * Returns an array of lightweight candidate DTOs.
     *
     * @return array<int, array{provider_id: string, title: string, rom_name: ?string, region: ?string, year: ?string, cover_url: ?string}>
     */
    public function search(Console $console, string $name): array
    {
        if ($console->screenscraperId === null || trim($name) === '') {
            return [];
        }

        $response = $this->call('jeuRecherche.php', [
            'systemeid' => $console->screenscraperId,
            'recherche' => $name,
        ]);

        $jeux = Arr::get($response, 'response.jeux', []);

        if (! is_array($jeux)) {
            return [];
        }

        return Collection::make($jeux)
            ->map(fn (array $jeu) => [
                'provider_id' => (string) Arr::get($jeu, 'id', ''),
                'title' => $this->pickLocalized(Arr::get($jeu, 'noms', []), 'text'),
                'rom_name' => Arr::get($jeu, 'rom.romfilename'),
                'region' => $this->firstRegion($jeu),
                'year' => $this->extractYear($this->pickLocalized(Arr::get($jeu, 'dates', []), 'text')),
                'cover_url' => $this->pickMedia(Arr::get($jeu, 'medias', []), MediaKind::Cover->screenScraperTypes()),
            ])
            ->filter(fn (array $c) => Arr::get($c, 'provider_id') !== '')
            ->values()
            ->all();
    }

    /**
     * Fetch a full, normalized record for a specific ScreenScraper game id.
     *
     * @return array<string, mixed>|null
     */
    public function fetchById(int $gameId): ?array
    {
        if ($gameId <= 0) {
            return null;
        }

        $response = $this->call('jeuInfos.php', ['gameid' => $gameId]);
        $jeu = Arr::get($response, 'response.jeu');

        return is_array($jeu) ? $this->normalize($jeu) : null;
    }

    /**
     * What the provider says about the account the credentials name.
     *
     * The cheapest call there is — no game, no media, just the `ssuser` block
     * every response carries anyway. Worth having on its own because that
     * block is the only place the real allowance is stated, and a login the
     * provider did not accept is not an error: it answers on the developer
     * account instead, with the developer account's smaller allowance, and
     * nothing in a game lookup says which of the two answered.
     *
     * @return array<string, mixed> the raw `ssuser` block, or [] if there is none
     *
     * @throws ScreenScraperException
     */
    public function account(): array
    {
        $user = Arr::get($this->call('ssuserInfos.php', []), 'response.ssuser');

        return is_array($user) ? $user : [];
    }

    /**
     * Fetch one media file.
     *
     * The URL is the stripped one from normalize(); credentials go back on
     * here, so nothing stored or logged ever carries them.
     *
     * Passing the checksum of a copy we already hold turns the request into a
     * question: the provider answers with the literal text MD5OK and no bytes
     * when it matches. That is worth doing even though the metadata response
     * already carried the checksum, because it also covers a file that changed
     * upstream since the last scrape.
     *
     * @throws ScreenScraperException
     */
    public function fetchMedia(string $url, ?string $knownMd5 = null): MediaFetch
    {
        $credentials = $this->credentials();

        // Media shares the account's thread and per-minute allowance with the
        // metadata calls, so it is paced the same way.
        $this->throttle();

        $query = array_filter([
            'devid' => Arr::get($credentials, 'dev_id'),
            'devpassword' => Arr::get($credentials, 'dev_password'),
            'softname' => self::SOFTNAME,
            'ssid' => Arr::get($credentials, 'user'),
            'sspassword' => Arr::get($credentials, 'password'),
            'md5' => $knownMd5 !== null ? strtolower($knownMd5) : null,
        ], fn (mixed $value) => $value !== '' && $value !== null);

        // Merged into the URL rather than passed alongside it: Guzzle replaces
        // an existing query string when given a separate array, which would
        // strip the jeuid and media parameters that say what to fetch and
        // leave every request asking for nothing.
        $response = $this->send($this->withQuery($url, $query));
        $body = $response->body();

        if ($error = $this->classify($response->status(), $this->readableBody($body))) {
            throw $error;
        }

        // Short, plain-text answers rather than an image. Checked by length
        // first so a small image is never mistaken for one of them.
        if (strlen($body) <= 16) {
            $marker = strtoupper(trim($body));

            if (in_array($marker, ['MD5OK', 'CRCOK', 'SHA1OK'], true)) {
                return MediaFetch::unchanged();
            }

            if ($marker === 'NOMEDIA' || $marker === '') {
                return MediaFetch::absent();
            }
        }

        return MediaFetch::downloaded($body);
    }

    /**
     * Reduce a ScreenScraper jeu object to the flat shape we persist.
     *
     * @param  array<string, mixed>  $jeu
     * @return array<string, mixed>
     */
    private function normalize(array $jeu): array
    {
        $medias = $this->sanitizeMedias(Arr::get($jeu, 'medias'));

        return [
            'provider_id' => (string) Arr::get($jeu, 'id', ''),
            'title' => $this->pickLocalized(Arr::get($jeu, 'noms', []), 'text'),
            'description' => $this->pickLocalized(Arr::get($jeu, 'synopsis', []), 'text', 'langue'),
            'cover_url' => $this->pickMedia(Arr::get($jeu, 'medias', []), MediaKind::Cover->screenScraperTypes()),
            'logo_url' => $this->pickMedia(Arr::get($jeu, 'medias', []), MediaKind::Logo->screenScraperTypes()),
            'backdrop_url' => $this->pickMedia(Arr::get($jeu, 'medias', []), MediaKind::Backdrop->screenScraperTypes()),
            'release_date' => $this->pickLocalized(Arr::get($jeu, 'dates', []), 'text'),
            'genre' => $this->flattenGenres(Arr::get($jeu, 'genres', [])),
            'region' => $this->firstRegion($jeu),
            'players' => (string) Arr::get($jeu, 'joueurs.text', ''),
            'publisher' => (string) Arr::get($jeu, 'editeur.text', ''),
            'developer' => (string) Arr::get($jeu, 'developpeur.text', ''),
            'rating' => $this->rating(Arr::get($jeu, 'note.text')),
            'medias' => $medias,
            'roms' => is_array($roms = Arr::get($jeu, 'roms')) ? $roms : [],
            // The media list inside raw is replaced by the sanitised one: the
            // URLs ScreenScraper hands out carry devpassword, ssid and
            // sspassword, and raw is what gets written to the activity log.
            'raw' => ['medias' => $medias] + $jeu,
        ];
    }

    /**
     * Every media the provider offers for this game, cleaned up.
     *
     * Two things are wrong with the list as it arrives. It mixes in artwork
     * belonging to the publisher, the genre and other related entities, which
     * `parent` distinguishes; and every URL is pre-signed with our developer
     * and account passwords, which must not reach the database or a log.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeMedias(mixed $medias): array
    {
        if (! is_array($medias)) {
            return [];
        }

        return Collection::make($medias)
            ->filter(fn ($media) => is_array($media) && Arr::get($media, 'parent') === 'jeu')
            ->map(fn (array $media) => [
                'type' => (string) Arr::get($media, 'type', ''),
                // Absent entirely on region-less media such as fanart and video.
                'region' => Arr::get($media, 'region'),
                'format' => Arr::get($media, 'format'),
                'url' => $this->stripCredentials((string) Arr::get($media, 'url', '')),
                'md5' => Arr::get($media, 'md5'),
                'crc' => Arr::get($media, 'crc'),
                'sha1' => Arr::get($media, 'sha1'),
                'size' => Arr::get($media, 'size') !== null ? (int) Arr::get($media, 'size') : null,
            ])
            ->filter(fn (array $media) => $media['type'] !== '' && $media['url'] !== '')
            ->values()
            ->all();
    }

    /**
     * Remove credential query parameters from a media URL.
     *
     * Kept separate from redact(): that one blanks values inside a log line,
     * while this has to leave a URL that still works once the credentials are
     * put back at download time.
     */
    private function stripCredentials(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);

        foreach (['devid', 'devpassword', 'ssid', 'sspassword'] as $secret) {
            unset($query[$secret]);
        }

        $rebuilt = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').($parts['path'] ?? '');

        return $query === [] ? $rebuilt : $rebuilt.'?'.http_build_query($query);
    }

    /**
     * Pick a localized field from a list like jeu.noms / jeu.dates / jeu.synopsis.
     * Prefers region=ss for noms/dates and langue=en for synopsis, falling back to the first entry.
     */
    private function pickLocalized(mixed $list, string $valueKey, string $discriminator = 'region'): ?string
    {
        if (! is_array($list) || $list === []) {
            return null;
        }

        $preferred = $discriminator === 'langue' ? self::PREFERRED_LANG : self::DEFAULT_REGION;

        $match = Collection::make($list)
            ->first(fn ($item) => is_array($item) && Arr::get($item, $discriminator) === $preferred);

        $value = Arr::get($match ?? Arr::first($list), $valueKey);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Pick the best media URL from jeu.medias for any of the given types.
     * Prefers English-region variants (us → wor → eu → …) before falling back
     * to whatever SS returns first (often fr).
     *
     * @param  string[]  $types
     */
    private function pickMedia(mixed $medias, array $types): ?string
    {
        if (! is_array($medias)) {
            return null;
        }

        foreach ($types as $type) {
            $ofType = Collection::make($medias)
                ->filter(fn ($media) => is_array($media) && Arr::get($media, 'type') === $type);

            if ($ofType->isEmpty()) {
                continue;
            }

            foreach (self::MEDIA_REGION_PRIORITY as $region) {
                $match = $ofType->first(fn ($media) => Arr::get($media, 'region') === $region);
                if ($match !== null && is_string($url = Arr::get($match, 'url')) && $url !== '') {
                    return $url;
                }
            }

            $fallback = $ofType->first();
            if (is_string($url = Arr::get($fallback, 'url')) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * The first region shortname the provider lists for a game.
     *
     * @param  array<string, mixed>  $jeu
     */
    private function firstRegion(array $jeu): ?string
    {
        $regions = Arr::get($jeu, 'regions.regions_shortname', []);
        if (is_array($regions) && $regions !== []) {
            return (string) $regions[0];
        }

        return Arr::get($jeu, 'rom.regions.regions_shortname.0');
    }

    /**
     * The four-digit year inside a provider date string.
     */
    private function extractYear(?string $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return preg_match('/(\d{4})/', $date, $m) ? $m[1] : null;
    }

    /**
     * Provider genres joined into one comma-separated string.
     */
    private function flattenGenres(mixed $genres): ?string
    {
        if (! is_array($genres)) {
            return null;
        }

        $names = Collection::make($genres)
            ->map(fn ($genre) => $this->pickLocalized(Arr::get($genre, 'noms', []), 'text'))
            ->filter()
            ->values()
            ->all();

        return $names === [] ? null : implode(', ', $names);
    }

    /**
     * ScreenScraper's `note`, from their scale of twenty onto ours of a
     * hundred.
     *
     * Zero is read as "nobody has voted", not as the mark zero: that is what
     * the provider sends for a game with no votes, and a library sorted by
     * rating would otherwise put those games below the ones it knows nothing
     * about at all.
     *
     * Everything else is tolerated rather than trusted — the field is
     * contributed, and an empty string, a comma decimal and a value past the
     * top of the scale have all been seen.
     */
    private function rating(mixed $note): ?int
    {
        if (is_string($note)) {
            $note = str_replace(',', '.', trim($note));
        }

        if (! is_numeric($note)) {
            return null;
        }

        $value = (float) $note;

        return $value <= 0.0 ? null : (int) round(min($value, 20.0) * 5);
    }

    /**
     * Perform an authenticated GET against the SS v2 API, returning the decoded body.
     * Returns [] on 404 / missing game; throws on other failures.
     *
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function call(string $endpoint, array $params): array
    {
        $credentials = $this->credentials();

        $this->throttle();

        $response = $this->send(
            $this->endpointUrl($credentials, $endpoint),
            $this->query($credentials, $params),
        );

        $decoded = $this->decode($response);

        // Recorded here rather than by each caller: the allowance changes with
        // every single request, and a caller that forgets leaves the throttle
        // working from stale numbers.
        ScreenScraperQuota::remember(Arr::get($decoded, 'response', []));

        return $decoded;
    }

    /**
     * Wait out the remainder of the minimum interval since the last request.
     *
     * Kept in the cache rather than a property because consecutive calls are
     * consecutive queue jobs in separate processes, and an instance property
     * would reset to nothing between them.
     */
    private function throttle(): void
    {
        $interval = (float) config('screenscraper.min_interval');

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

    /**
     * Provider credentials, refusing to call out without the dev pair.
     *
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function credentials(): array
    {
        $credentials = (array) config('screenscraper');

        if (Arr::get($credentials, 'dev_id') === '' || Arr::get($credentials, 'dev_password') === '') {
            throw new RuntimeException('ScreenScraper dev credentials missing — see config/screenscraper.php.');
        }

        // The account is settings, not config: it is set in Settings →
        // ScreenScraper and the rest of this array is deployment.
        $credentials['user'] = ScreenScraperCredentials::user();
        $credentials['password'] = ScreenScraperCredentials::password();

        return $credentials;
    }

    /**
     * Query parameters for a call.
     *
     * The v2 API only accepts credentials as query parameters, so anything that
     * echoes a request URL must go through redact() first.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function query(array $credentials, array $params): array
    {
        return array_filter([
            'devid' => Arr::get($credentials, 'dev_id'),
            'devpassword' => Arr::get($credentials, 'dev_password'),
            'softname' => self::SOFTNAME,
            'ssid' => Arr::get($credentials, 'user'),
            'sspassword' => Arr::get($credentials, 'password'),
            'output' => 'json',
            ...$params,
        ], fn (mixed $value) => $value !== '' && $value !== null);
    }

    /**
     * Add parameters to a URL that already carries some.
     *
     * Guzzle replaces an existing query string when handed a separate array,
     * which would strip the jeuid and media parameters that say what to fetch
     * and leave every media request asking for nothing at all.
     *
     * @param  array<string, mixed>  $extra
     */
    private function withQuery(string $url, array $extra): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);

        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').($parts['path'] ?? '');

        return $base.'?'.http_build_query([...$query, ...$extra]);
    }

    /**
     * Absolute URL for one API endpoint.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function endpointUrl(array $credentials, string $endpoint): string
    {
        $base = Arr::get($credentials, 'endpoint', 'https://api.screenscraper.fr/api2');

        return rtrim((string) $base, '/').'/'.ltrim($endpoint, '/');
    }

    /**
     * Issue the request, translating a transport failure into a redacted one.
     *
     *
     * A null query leaves the URL's own query string alone. An array — even
     * an empty one — replaces it, which silently strips everything the URL
     * already carried.
     *
     * @param  array<string, mixed>|null  $query
     *
     * @throws ServerError
     */
    private function send(string $url, ?array $query = null): Response
    {
        try {
            return Http::withUserAgent(self::SOFTNAME)
                ->connectTimeout((int) config('screenscraper.connect_timeout'))
                ->timeout((int) config('screenscraper.timeout'))
                ->get($url, $query);
        } catch (ConnectionException $e) {
            // Guzzle appends the whole request URL to its connection errors,
            // and the credentials live in the query string.
            throw new ServerError('ScreenScraper unreachable: '.$this->redact($e->getMessage()));
        }
    }

    /**
     * Decode a response body. A miss is [] rather than an error; anything else throws.
     *
     * The distinction matters more than it looks. A spent quota, a closed API
     * and a blacklisted client all arrive as a non-JSON body that decodes to
     * nothing, and reading that as "this game is not in the database" marks an
     * entire library unmatched without one line of error anywhere.
     *
     *
     * @return array<string, mixed>
     *
     * @throws ScreenScraperException
     */
    private function decode(Response $response): array
    {
        $status = $response->status();
        $body = $this->readableBody($response->body());

        // Asked before the error classifier, because 400 carries both meanings:
        // a malformed request, and on some responses an ordinary miss. The body
        // is what tells them apart.
        if ($status === 404 || $this->bodyMatches($body, self::BODY_NOT_FOUND)) {
            return [];
        }

        if ($error = $this->classify($status, $body)) {
            throw $error;
        }

        $json = $this->extractJson($response->body());

        if (is_array($json)) {
            return $json;
        }

        // 2xx with a body we cannot read and cannot name. Not a miss — we do
        // not know what it is, and guessing "no match" is the failure mode this
        // whole method exists to prevent.
        throw new ServerError(
            'ScreenScraper returned an unreadable body: '.$this->redact(mb_substr($body, 0, 200)),
            $status,
            $body,
        );
    }

    /**
     * Map a status code and body onto a typed failure, or null when the
     * response is something the caller can work with.
     *
     * The body is consulted as well as the status because the two disagree in
     * practice: 400 covers both a malformed request and, on some responses, an
     * ordinary miss.
     */
    private function classify(int $status, string $body): ?ScreenScraperException
    {
        $message = fn (string $what): string => "ScreenScraper: {$what} (HTTP {$status})";

        // Text first — it is the more reliable of the two.
        if ($this->bodyMatches($body, self::BODY_FAILED_QUOTA)) {
            return new FailedLookupQuotaExhausted($message('daily failed-lookup quota exhausted'), $status, $body);
        }

        if ($this->bodyMatches($body, self::BODY_QUOTA)) {
            return new QuotaExhausted($message('daily quota exhausted'), $status, $body);
        }

        if ($this->bodyMatches($body, self::BODY_BLACKLISTED)) {
            return new SoftwareBlacklisted($message('this softname is blacklisted'), $status, $body);
        }

        if ($this->bodyMatches($body, self::BODY_THREADS)) {
            return new ThreadLimitReached($message('thread limit reached'), $status, $body);
        }

        if ($this->bodyMatches($body, self::BODY_CLOSED)) {
            return new ApiUnavailable($message('API closed'), $status, $body);
        }

        if ($this->bodyMatches($body, self::BODY_BAD_USER)) {
            return new BadCredentials(
                $message('user login rejected — check the account in Settings → ScreenScraper'),
                $status,
                $body,
            );
        }

        return match (true) {
            $status === 430 => new QuotaExhausted($message('daily quota exhausted'), $status, $body),
            $status === 431 => new FailedLookupQuotaExhausted($message('daily failed-lookup quota exhausted'), $status, $body),
            $status === 429 => new ThreadLimitReached($message('thread limit reached'), $status, $body),
            $status === 426 => new SoftwareBlacklisted($message('this softname is blacklisted'), $status, $body),
            // 401 is not about our credentials: ScreenScraper sheds non-members
            // whenever its own CPU passes 60 %.
            $status === 401, $status === 423 => new ApiUnavailable($message('API closed'), $status, $body),
            // Reached only when the body said nothing recognisable: a 403 whose
            // text names the user pair is caught above, and this is the other
            // one, which is the developer pair.
            $status === 403 => new BadCredentials($message('developer credentials rejected'), $status, $body),
            // A 400 that is not a miss is our bug: a path in romnom, a
            // malformed hash, a missing mandatory field.
            $status === 400 => new InvalidRequest($message('malformed request: '.$this->redact(mb_substr($body, 0, 120))), $status, $body),
            $status >= 500 => new ServerError($message('server error'), $status, $body),
            default => null,
        };
    }

    /**
     * The response body as UTF-8, lowercased, for matching.
     *
     * Error bodies come back Latin-1 despite the JSON content type, so
     * "dépassé" arrives as an invalid UTF-8 sequence and any match against it
     * silently fails.
     */
    private function readableBody(string $body): string
    {
        if (! mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
        }

        return mb_strtolower(trim($body));
    }

    /**
     * @param  string[]  $needles
     */
    private function bodyMatches(string $body, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($body, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip credential values out of a message before it is thrown or logged.
     * The API only accepts them as query parameters, and Guzzle appends the full
     * request URL to its connection errors.
     */
    private function redact(string $message): string
    {
        return (string) preg_replace(
            '/\b(devid|devpassword|ssid|sspassword)=[^&\s]*/i',
            '$1=REDACTED',
            $message,
        );
    }

    /**
     * Decode the response body, tolerating the plain-text quota and auth notices
     * ScreenScraper prepends to otherwise valid JSON.
     */
    private function extractJson(string $body): mixed
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Try to find a JSON object embedded in the response body.
        $start = strpos($body, '{');
        $end = strrpos($body, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return json_decode(substr($body, $start, $end - $start + 1), true);
        }

        return null;
    }
}
