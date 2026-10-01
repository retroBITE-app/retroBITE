<?php

use App\Jobs\MeasureLibrary;
use App\Models\AppSetting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * The first scheduled work in this application, so it needs `schedule:work`
 * running alongside the queue workers — see docker/web/entrypoint.sh. Every
 * one of these can also be run by hand, so the schedule is a convenience
 * rather than a dependency.
 */

// Weekly, overnight: a console's index is megabytes, changes slowly, and this
// is where games that had no set when they were scanned quietly become
// identified. Daily was ninety requests a night for an index that had not
// moved, against an API behind Cloudflare's rate limit.
Schedule::command('retrobite:ra:sync-hashes --queue')->weeklyOn(0, '03:00');

// Sets for anything identified and still without one. Weekly: a game
// identified in the meantime has its set already — IdentifyGame queues it —
// so this only catches what that missed.
Schedule::command('retrobite:ra:sync-sets --missing --queue')->weeklyOn(0, '03:30');

// Every set again, monthly: achievements are added, removed and re-scored,
// but seldom.
Schedule::command('retrobite:ra:sync-sets --queue')->monthlyOn(1, '05:00');

// The cheap pulse: one request per person for everything unlocked lately.
Schedule::command('retrobite:ra:sync-progress')->everyFifteenMinutes()->withoutOverlapping();

// The full reconciliation, which catches what the pulse cannot see: revoked
// unlocks, re-scored sets, and any window the pulse missed.
Schedule::command('retrobite:ra:sync-progress --full')->dailyAt('04:00');

// What the consoles page, the shelf, the dashboard and the sidebar show about
// the library disk: files per console and the free space. Measured here so no
// page ever reads the disk; this is how files copied in over the share show up
// without a scan. Unique, so it cannot pile up behind a slow disk.
Schedule::job(new MeasureLibrary)->everyFifteenMinutes();

// Games whose files have all been gone longer than Settings → Library allows,
// after the night's RetroAchievements work, when that page has it on.
Schedule::command('retrobite:library:prune')->dailyAt('04:30')->when(function (): bool {
    return AppSetting::enabled(AppSetting::PRUNE_MISSING_AUTO);
});
