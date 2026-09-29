<?php

use App\Services\ScreenScraperService;
use App\Support\ScreenScraperQuota;
use Illuminate\Support\Facades\Http;

/**
 * Which account the provider answers as, and why a big allowance can arrive small.
 *
 * ScreenScraper does not reject an ssid it does not recognise. It answers on
 * the developer credentials instead, with the developer account's allowance,
 * and a game lookup looks identical either way — same shape, same fields, a
 * smaller number. So the only way to tell a 100 000-request account from a
 * silent fallback to a 10 000-request one is to read the `ssuser` block and
 * see whether it names anybody.
 */
function ssAccountBody(array $ssuser): string
{
    return json_encode(['response' => ['ssuser' => $ssuser]]);
}

beforeEach(function () {
    config()->set('screenscraper.user', 'somebody');
    config()->set('screenscraper.password', 'secret');
});

it('sends the login the config carries', function () {
    Http::fake(['*' => Http::response(ssAccountBody(['id' => 'somebody']), 200)]);

    app(ScreenScraperService::class)->account();

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), 'ssuserInfos.php')
            && ($query['ssid'] ?? null) === 'somebody'
            && ($query['sspassword'] ?? null) === 'secret';
    });
});

/**
 * The regression this file exists for. An empty SCREENSCRAPER_USER is not a
 * configuration error anything shouts about — array_filter drops the pair, the
 * request goes out on the developer credentials alone, and every lookup keeps
 * working on the developer account's smaller allowance.
 */
it('asks anonymously when no login is configured', function () {
    config()->set('screenscraper.user', '');
    config()->set('screenscraper.password', '');

    Http::fake(['*' => Http::response(ssAccountBody(['id' => '']), 200)]);

    app(ScreenScraperService::class)->account();

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ! array_key_exists('ssid', $query)
            && ! array_key_exists('sspassword', $query)
            // The developer pair still goes, which is what makes the request
            // succeed rather than fail in a way somebody would notice.
            && ($query['devid'] ?? '') !== '';
    });
});

it('hands back the whole ssuser block rather than a reading of it', function () {
    Http::fake(['*' => Http::sequence()
        ->push(ssAccountBody([
            'id' => 'somebody',
            'niveau' => '3',
            'maxrequestsperday' => '100000',
            'requeststoday' => '412',
        ]), 200)
        ->push(ssAccountBody([
            'id' => '',
            'maxrequestsperday' => '10000',
            'requeststoday' => '3',
        ]), 200)]);

    // Raw on purpose: this is a diagnostic, and a field the provider adds
    // later should turn up without this method having to learn about it.
    expect(app(ScreenScraperService::class)->account())
        ->toMatchArray([
            'id' => 'somebody',
            'niveau' => '3',
            'maxrequestsperday' => '100000',
        ]);

    // Nor is an empty name filled in: that is how the provider says it fell
    // back to the developer account, and the caller is the one to say so.
    expect(app(ScreenScraperService::class)->account())
        ->toMatchArray(['id' => '', 'maxrequestsperday' => '10000']);
});

it('says nothing rather than inventing an account when the block is missing', function () {
    Http::fake(['*' => Http::response(json_encode(['response' => []]), 200)]);

    expect(app(ScreenScraperService::class)->account())->toBe([]);
});

it('records the allowance it was just told about', function () {
    Http::fake(['*' => Http::response(ssAccountBody([
        'id' => 'somebody',
        'maxthreads' => '4',
        'requeststoday' => '412',
        'maxrequestsperday' => '100000',
        'requestskotoday' => '9',
        'maxrequestskoperday' => '10000',
    ]), 200)]);

    app(ScreenScraperService::class)->account();

    // Straight through the same recording every other call goes through, so
    // asking this question also refreshes what the sidebar shows.
    expect(ScreenScraperQuota::current())
        ->toMatchArray([
            'max_requests_per_day' => 100000,
            'requests_today' => 412,
            'max_threads' => 4,
        ])
        ->and(ScreenScraperQuota::remaining())->toBe(99588);
});

/**
 * The shape of the answer when the login was not accepted: the numbers are
 * plausible, nothing is an error, and `id` is the only thing that differs.
 */
