<?php

use App\Exceptions\ScreenScraper\ApiUnavailable;
use App\Exceptions\ScreenScraper\BadCredentials;
use App\Exceptions\ScreenScraper\FailedLookupQuotaExhausted;
use App\Exceptions\ScreenScraper\InvalidRequest;
use App\Exceptions\ScreenScraper\QuotaExhausted;
use App\Exceptions\ScreenScraper\ServerError;
use App\Exceptions\ScreenScraper\SoftwareBlacklisted;
use App\Exceptions\ScreenScraper\ThreadLimitReached;
use App\Services\ScreenScraperService;
use App\Support\Console;
use App\Support\ScreenScraperQuota;
use Illuminate\Support\Facades\Http;

/**
 * ScreenScraper answers errors with a bare Latin-1 French sentence under a JSON
 * content type. Decoding that yields nothing, and the danger is reading nothing
 * as "this game is not in the database" — which would mark a whole library
 * unmatched the moment a scan runs past the daily quota.
 */
function ssBody(string $text): string
{
    return mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
}

function ssLookup(): ?array
{
    return app(ScreenScraperService::class)
        ->lookup(new Console('snes'), ['romnom' => 'Super Mario World (USA).sfc', 'romtaille' => 524288]);
}

beforeEach(function () {
    config()->set('screenscraper.user', 'tester');
    config()->set('screenscraper.password', 'secret');
});

it('throws rather than reporting a miss when the daily quota is spent', function () {
    Http::fake(['*' => Http::response(ssBody('Votre quota de scrape est dépassé pour aujourd\'hui !'), 430)]);

    ssLookup();
})->throws(QuotaExhausted::class);

it('distinguishes the scarce failed-lookup quota from the ordinary one', function () {
    Http::fake(['*' => Http::response(ssBody('Faite du tri dans vos fichiers roms et repassez demain !'), 431)]);

    try {
        ssLookup();
        $this->fail('expected a failed-lookup quota exception');
    } catch (FailedLookupQuotaExhausted $e) {
        // It is still a quota problem, so a caller that only knows about the
        // general case keeps working.
        expect($e)->toBeInstanceOf(QuotaExhausted::class)
            ->and($e->retryable())->toBeTrue()
            ->and($e->retryAfter())->toBeGreaterThan(60);
    }
});

it('classifies the remaining documented failure codes', function (int $status, string $text, string $expected) {
    Http::fake(['*' => Http::response(ssBody($text), $status)]);

    expect(fn () => ssLookup())->toThrow($expected);
})->with([
    'threads exceeded' => [429, 'Le nombre de threads autorisé pour le membre est atteint', ThreadLimitReached::class],
    'closed to non-members' => [401, 'API fermé pour les non membres ou les membres inactifs', ApiUnavailable::class],
    'closed entirely' => [423, 'API totalement fermé', ApiUnavailable::class],
    'blacklisted softname' => [426, 'Le logiciel de scrape utilisé a été blacklisté', SoftwareBlacklisted::class],
    'bad developer login' => [403, 'Erreur de login : Vérifier vos identifiants développeur !', BadCredentials::class],
    'malformed request' => [400, 'Champ crc, md5 ou sha1 erroné', InvalidRequest::class],
    'server fault' => [500, 'oops', ServerError::class],
]);

it('reports a genuine miss as null, not as an error', function () {
    Http::fake(['*' => Http::response(ssBody('Erreur : Jeu non trouvée !'), 404)]);

    expect(ssLookup())->toBeNull();
});

it('treats a 400 that says "not found" as a miss rather than a bug', function () {
    Http::fake(['*' => Http::response(ssBody('Erreur : Rom/Iso/Dossier non trouvée !'), 400)]);

    expect(ssLookup())->toBeNull();
});

it('never lets an unreadable success body look like a miss', function () {
    Http::fake(['*' => Http::response('<html>maintenance</html>', 200)]);

    ssLookup();
})->throws(ServerError::class);

it('keeps credentials out of the exception message', function () {
    Http::fake(['*' => Http::response(ssBody('Champ crc, md5 ou sha1 erroné devpassword=hunter2 sspassword=secret'), 400)]);

    try {
        ssLookup();
        $this->fail('expected InvalidRequest');
    } catch (InvalidRequest $e) {
        expect($e->getMessage())->not->toContain('hunter2')
            ->and($e->getMessage())->not->toContain('secret')
            ->and($e->getMessage())->toContain('REDACTED');
    }
});

it('records the account allowance from a successful response', function () {
    ScreenScraperQuota::forget();

    Http::fake(['*' => Http::response([
        'response' => [
            'ssuser' => [
                'niveau' => '1', 'maxthreads' => '1', 'requeststoday' => '42',
                'maxrequestsperday' => '20000', 'requestskotoday' => '7',
                'maxrequestskoperday' => '2000', 'maxdownloadspeed' => '128',
            ],
            'serveurs' => ['closefornomember' => '0', 'closeforleecher' => '0'],
            'jeu' => ['id' => '1234', 'noms' => [['region' => 'ss', 'text' => 'Super Mario World']]],
        ],
    ], 200)]);

    $game = ssLookup();

    expect($game['provider_id'])->toBe('1234')
        ->and($game['title'])->toBe('Super Mario World');

    $quota = ScreenScraperQuota::current();

    expect($quota['max_threads'])->toBe(1)
        ->and($quota['requests_today'])->toBe(42)
        ->and(ScreenScraperQuota::remaining())->toBe(19958)
        // The scarce one: ten times smaller, and the first to run out.
        ->and(ScreenScraperQuota::failedRemaining())->toBe(1993);
});
