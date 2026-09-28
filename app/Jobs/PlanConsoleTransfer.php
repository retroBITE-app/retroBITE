<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TransferFailure;
use App\Events\SystemUpdated;
use App\Exceptions\TransferFailed;
use App\Models\Transfer;
use App\Support\LiveUpdates;
use App\Transfers\Endpoints\Endpoints;
use App\Transfers\SendToShare;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * The first step of sending a console to a share: ask the share what it
 * already holds, one listing per folder, and queue only the games that are
 * missing something. See SendToShare::planConsole().
 *
 * On the queue because it is a network call, and a page never waits on one.
 */
class PlanConsoleTransfer implements ShouldQueue
{
    use Queueable;

    /** A listing of a folder of a few thousand files over a slow link. */
    public int $timeout = 300;

    /** Only a share that stopped answering is worth a second go. */
    public int $tries = 2;

    public int $backoff = 30;

    /** @param  list<int>  $transferIds  every game of the send */
    public function __construct(public readonly array $transferIds)
    {
        $this->onConnection('database-long')->onQueue('transfer');
    }

    public function handle(SendToShare $sender, Endpoints $endpoints): void
    {
        try {
            $sender->planConsole($this->transferIds, $endpoints);
        } catch (TransferFailed $e) {
            if ($e->reason->retryable() && $this->attempts() < $this->tries) {
                throw $e;
            }

            $this->giveUp($e->reason);
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->giveUp($e instanceof TransferFailed ? $e->reason : TransferFailure::Rejected);
    }

    /** The share could not be listed: nothing was copied, so every game failed, and why. */
    private function giveUp(TransferFailure $reason): void
    {
        Transfer::query()
            ->whereKey($this->transferIds)
            ->whereIn('status', [Transfer::QUEUED, Transfer::RUNNING])
            ->update(['status' => Transfer::FAILED, 'failure' => $reason, 'finished_at' => now()]);

        LiveUpdates::system(SystemUpdated::TRANSFER);
    }
}
