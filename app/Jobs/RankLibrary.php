<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\LibraryRanking;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Work out the retroBite rank again, after a score moved (LibraryRanking).
 *
 * Unique until it starts, so a scan identifying a thousand games queues one
 * of these rather than a thousand, and a score that moves while it runs
 * still queues the next.
 */
class RankLibrary implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(LibraryRanking $ranking): void
    {
        $ranking->rebuild();
    }
}
