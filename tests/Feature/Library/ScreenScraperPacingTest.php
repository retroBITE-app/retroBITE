<?php

use App\Exceptions\ScreenScraper\ThreadLimitReached;
use App\Services\ScreenScraperService;
use App\Support\ScreenScraperQuota;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Requests go out in thread slots: as many as the account has threads, each
 * held for the whole request and paced on its own. Short intervals here so
 * the suite is not slowed by the real 1.2 s.
 */

beforeEach(function () {
    config()->set('screenscraper.min_interval', 0.2);
    config()->set('screenscraper.slot_wait', 0.3);

    Http::fake(['*' => Http::response(['response' => ['jeu' => [
        'id' => '19256',
        'noms' => [['region' => 'ss', 'text' => 'Final Fantasy IX']],
    ]]], 200)]);
});

/** The account's thread count, as the provider's last answer would record it. */
function accountThreads(int $threads): void
{
    ScreenScraperQuota::remember(['ssuser' => ['maxthreads' => $threads]]);
}

it('uses another slot while one is busy, up to the account\'s threads', function () {
    accountThreads(2);

    // Another worker is mid-request on the first slot.
    $busy = Cache::lock('screenscraper.thread.0', 150);
    $busy->get();

    app(ScreenScraperService::class)->fetchById(19256);

    expect(Cache::get('screenscraper.thread.1.last'))->not->toBeNull();

    $busy->release();
});

it('gives the job back rather than opening a connection past the account\'s threads', function () {
    accountThreads(1);

    $busy = Cache::lock('screenscraper.thread.0', 150);
    $busy->get();

    try {
        expect(fn () => app(ScreenScraperService::class)->fetchById(19256))->toThrow(ThreadLimitReached::class);
        Http::assertNothingSent();
    } finally {
        $busy->release();
    }
});

it('frees the slot when the request is done', function () {
    accountThreads(1);

    app(ScreenScraperService::class)->fetchById(19256);

    expect(Cache::lock('screenscraper.thread.0', 1)->get())->toBeTrue();
});

it('keeps the pause within a slot', function () {
    accountThreads(1);
    $service = app(ScreenScraperService::class);

    $service->fetchById(19256);
    $started = microtime(true);
    $service->fetchById(19256);

    expect(microtime(true) - $started)->toBeGreaterThanOrEqual(0.19);
});

it('takes one slot before the account has been heard from', function () {
    // No snapshot yet: one thread, the plain account's, is the safe guess.
    $busy = Cache::lock('screenscraper.thread.0', 150);
    $busy->get();

    try {
        expect(fn () => app(ScreenScraperService::class)->fetchById(19256))->toThrow(ThreadLimitReached::class);
    } finally {
        $busy->release();
    }
});
