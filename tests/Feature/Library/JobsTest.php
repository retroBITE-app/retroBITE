<?php

use App\Jobs\HashFile;
use App\Jobs\MatchGame;
use App\Jobs\RetroAchievements\HashGame;
use App\Jobs\RetroAchievements\IdentifyGame;
use App\Jobs\RetroAchievements\ReconcileProgress;
use App\Jobs\RetroAchievements\SyncGameProgress;
use App\Jobs\RetroAchievements\SyncHashIndex;
use App\Jobs\RetroAchievements\SyncRecentUnlocks;
use App\Jobs\RetroAchievements\SyncSet;
use App\Jobs\ScanConsoleFolder;
use App\Jobs\ScrapeGameMedia;
use Illuminate\Support\Facades\DB;

/**
 * Pushing onto a real queue, not a fake one.
 *
 * Queue::fake() and the sync driver both skip the path that serialises a job,
 * and that path asks the job for things — backoff(), retryUntil(), uniqueId().
 * A private method whose name matches one of those is fatal when the framework
 * reaches for it, and every test that faked the queue said the job was fine.
 */
it('can be pushed onto a real queue', function (string $job, array $arguments) {
    config()->set('queue.default', 'database');
    DB::table('jobs')->delete();

    $job::dispatch(...$arguments);

    expect(DB::table('jobs')->count())->toBe(1);
})->with([
    'match' => [MatchGame::class, [1]],
    'match with checksums' => [MatchGame::class, [1, true]],
    'scrape media' => [ScrapeGameMedia::class, [1, []]],
    'scan' => [ScanConsoleFolder::class, ['snes']],
    'hash' => [HashFile::class, [1]],

    // The RetroAchievements jobs are the first in this codebase to implement
    // ShouldBeUnique, so they are the first whose uniqueId() the framework
    // calls during dispatch — which is exactly the kind of thing this test
    // exists to catch.
    'ra index' => [SyncHashIndex::class, [3]],
    'ra hash' => [HashGame::class, [1]],
    'ra identify' => [IdentifyGame::class, [1]],
    'ra set' => [SyncSet::class, [1]],
    'ra recent' => [SyncRecentUnlocks::class, [1]],
    'ra reconcile' => [ReconcileProgress::class, [1]],
    'ra game progress' => [SyncGameProgress::class, [1, 1]],
]);
