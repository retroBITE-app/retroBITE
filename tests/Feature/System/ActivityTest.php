<?php

use App\Support\SystemActivity;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // The high-water marks live in the cache, which the array store keeps for
    // the length of a test but not the length of a run. Cleared anyway, so a
    // test cannot inherit a denominator from the one before it.
    SystemActivity::forget();
});

it('says nothing is happening when the table is empty', function () {
    $activity = SystemActivity::current();

    expect($activity->busy())->toBeFalse()
        ->and($activity->active())->toBe([])
        ->and($activity->remaining())->toBe(0);
});

it('speaks in the labels the sidebar uses, in the order work flows', function () {
    queueRow('ra');
    queueRow('default');
    queueRow('scraper');

    $labels = array_map(fn ($queue) => $queue->label, SystemActivity::current()->active());

    expect($labels)->toBe(['Scanning', 'Identifying', 'Achievements']);
});

it('folds the two hashing queues into one row, because the same workers serve both', function () {
    queueRow('hash');
    queueRows('ra-hash', 2);

    $active = SystemActivity::current()->active();

    expect($active)->toHaveCount(1)
        ->and($active[0]->label)->toBe('Hashing')
        ->and($active[0]->remaining())->toBe(3);
});

it('counts a queue nobody listed rather than dropping it', function () {
    queueRow('something-new');

    $activity = SystemActivity::current();

    // A total smaller than the table is the one answer this must never give:
    // it would read as an idle system with work sitting in it.
    expect($activity->remaining())->toBe(1)
        ->and($activity->active()[0]->label)->toBe('something-new');
});

it('tells a job a worker is holding from one nobody has taken from one that released itself', function () {
    queueRow('scraper', reservedAt: now()->getTimestamp());
    queueRow('scraper');
    queueRow('scraper', availableAt: now()->addHour()->getTimestamp());

    $queue = SystemActivity::current()->active()[0];

    expect($queue->running)->toBe(1)
        ->and($queue->pending)->toBe(1)
        ->and($queue->delayed)->toBe(1)
        ->and($queue->remaining())->toBe(3)
        ->and($queue->waiting())->toBeFalse();
});

it('calls a queue waiting when everything left is scheduled for later', function () {
    // What a spent ScreenScraper allowance looks like from here: rows that are
    // doing the right thing and will not move for hours.
    queueRow('scraper', availableAt: now()->addHour()->getTimestamp());

    expect(SystemActivity::current()->active()[0]->waiting())->toBeTrue();
});

it('measures the drain against the deepest the queue got', function () {
    queueRows('scraper', 10);
    SystemActivity::current();

    DB::table('jobs')->limit(6)->delete();

    expect(SystemActivity::current()->active()[0]->percent())->toBe(60);
});

it('forgets the mark when the queue empties, so the next run is measured against itself', function () {
    queueRows('scraper', 10);
    SystemActivity::current();

    DB::table('jobs')->delete();
    SystemActivity::current();

    queueRows('scraper', 4);

    expect(SystemActivity::current()->active()[0]->percent())->toBe(0);
});

it('never shows a full bar while a job is left', function () {
    queueRows('scraper', 200);
    SystemActivity::current();

    // 199 done of 200 rounds to 100, and a full bar over a queue that is still
    // working reads as a broken bar rather than as nearly finished.
    DB::table('jobs')->limit(199)->delete();

    expect(SystemActivity::current()->active()[0]->percent())->toBe(99);
});

it('raises the mark when a chain adds work mid-run rather than overflowing the bar', function () {
    queueRows('hash', 4);
    SystemActivity::current();

    queueRows('hash', 4);

    $queue = SystemActivity::current()->active()[0];

    expect($queue->peak)->toBe(8)
        ->and($queue->percent())->toBe(0);
});
