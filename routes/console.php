<?php

use App\Jobs\MeasureLibrary;
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

// Overnight, because a console's index is megabytes and this is where games
// that had no set when they were scanned quietly become identified.
Schedule::command('retrobite:ra:sync-hashes --queue')->dailyAt('03:00');

// Sets for anything identified since the last run.
Schedule::command('retrobite:ra:sync-sets --missing --queue')->dailyAt('03:30');

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
