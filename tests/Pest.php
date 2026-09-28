<?php

use App\Models\AppSetting;
use App\Support\ConsoleOverrides;
use App\Transfers\Smb\ShareClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Fakes\RefusingShareClient;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // No test may reach the network. A fake whose URL pattern does not
        // match otherwise falls through to the real ScreenScraper API, which
        // answers with something plausible and hides the bug the test was
        // written to catch — that is exactly how a stripped query string got
        // past a green suite once already.
        Http::preventStrayRequests();

        // And no test may start a process. The same trap as above wearing
        // different clothes: a Process::fake() whose pattern does not match
        // would fall through to the real RAHasher, which on a developer's
        // machine means reading an actual disc image for minutes, and in CI
        // means a binary that is not there.
        Process::preventStrayProcesses();

        // Nor a network share: smbclient is a process too, just not one the
        // Process facade starts.
        app()->instance(ShareClient::class, new RefusingShareClient);

        // AppSetting memoises for the length of a request, and that static
        // outlives RefreshDatabase — which empties the table underneath it and
        // would otherwise hand the next test the previous one's answer.
        AppSetting::flush();

        // And the console overrides ride along, for the same reason the queue
        // worker re-applies them: the memo above outlives the application, so
        // the providers booted this test's config repository from the previous
        // test's answer before RefreshDatabase had emptied the table.
        ConsoleOverrides::apply();

        // Every test but onboarding's own starts on an install that has been
        // set up; otherwise each signed-in page would answer with a redirect
        // into the wizard. OnboardingTest puts it back where it needs to.
        AppSetting::put(AppSetting::IS_ONBOARDED, true);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A row in `jobs`, written rather than queued.
 *
 * The suite runs on the sync connection, so a dispatch never leaves anything
 * behind to count. Real columns, because queue depth is read straight off them:
 * a reserved row is one a worker is holding, and an `available_at` in the
 * future is a job that released itself.
 */
function queueRow(string $queue, ?int $reservedAt = null, ?int $availableAt = null): void
{
    DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => $reservedAt,
        'available_at' => $availableAt ?? now()->getTimestamp(),
        'created_at' => now()->getTimestamp(),
    ]);
}

/**
 * The same, in bulk. One insert rather than a few hundred, because the tests
 * that care about a percentage need a denominator big enough for rounding to
 * matter.
 *
 * @param  positive-int  $times
 */
function queueRows(string $queue, int $times): void
{
    $now = now()->getTimestamp();

    DB::table('jobs')->insert(array_fill(0, $times, [
        'queue' => $queue,
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => $now,
        'created_at' => $now,
    ]));
}
