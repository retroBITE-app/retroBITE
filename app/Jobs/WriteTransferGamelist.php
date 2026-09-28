<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TransferFailure;
use App\Events\GameUpdated;
use App\Events\SystemUpdated;
use App\Exceptions\TransferFailed;
use App\Models\Transfer;
use App\Support\LiveUpdates;
use App\Transfers\Endpoints\Endpoints;
use App\Transfers\Location;
use App\Transfers\TransferRejected;
use App\Transfers\TransferTargets;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The last step of sending to a share: the games' entries in the target's
 * game list, merged into whatever list is there. It only runs once the files
 * have arrived — chained after one game's FileTransferJob, or run once a
 * console's batch is through, for every game in it whose files arrived.
 *
 * Not a file transfer — the list is written, not copied — and the one write
 * to a destination that replaces a file. Everyone else's entries stay as they
 * were; a list that cannot be read is left alone and the transfer fails.
 */
class WriteTransferGamelist implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 2;

    public int $backoff = 30;

    /**
     * @param  int|null  $transferId  one game sent from its page
     * @param  list<int>  $console  every game of a console sent at once
     */
    public function __construct(
        public readonly ?int $transferId = null,
        public readonly array $console = [],
    ) {
        $this->onConnection('database-long')->onQueue('transfer');
    }

    /** @param  list<int>  $transferIds */
    public static function forConsole(array $transferIds): self
    {
        return new self(console: $transferIds);
    }

    public function handle(Endpoints $endpoints): void
    {
        $transfers = $this->transfers();

        // A game whose files never arrived has no entry to write. One its
        // job gave up on is already marked failed; one never picked up is
        // said to have failed here, rather than left waiting for ever.
        foreach ($transfers->where('status', Transfer::QUEUED) as $stranded) {
            $this->finish(new Collection([$stranded]), TransferFailure::Rejected);
        }

        $arrived = $transfers->where('status', Transfer::RUNNING)->values();
        $first = $arrived->first();

        if ($first === null || $first->destination === null) {
            $this->signalBatch();

            return;
        }

        $target = TransferTargets::find($first->target);

        if ($target === null) {
            $this->finish($arrived, TransferFailure::Rejected);

            return;
        }

        try {
            $path = $target->plan($first->game)->gamelist;
            $share = $endpoints->resolve(Location::destination($first->destination, $path)->endpoint);

            // Read, merged and written under a lock: with several transfer
            // workers, two sends to one box could otherwise both read the
            // list before either wrote it, and one game's entry would go.
            Cache::lock('transfer-gamelist:'.$first->destination->id.':'.$path, 120)->block(60, function () use ($share, $path, $target, $arrived): void {
                $share->replace($path, $target->mergeGamelist($share->read($path), ...$arrived->pluck('game')->all()));
            });
        } catch (LockTimeoutException) {
            // Another send is writing this list and did not finish in a
            // minute; try again after the backoff rather than write blind.
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff);

                return;
            }

            $this->finish($arrived, TransferFailure::Unreachable);

            return;
        } catch (TransferRejected $e) {
            Log::warning('A game list on a share could not be merged.', ['transfer' => $first->id, 'games' => $arrived->count(), 'reason' => $e->getMessage()]);
            $this->finish($arrived, TransferFailure::Rejected);

            return;
        } catch (TransferFailed $e) {
            if ($e->reason->retryable() && $this->attempts() < $this->tries) {
                throw $e;
            }

            $this->finish($arrived, $e->reason);

            return;
        }

        $this->finish($arrived, null);
    }

    public function failed(?Throwable $e): void
    {
        $this->finish(
            $this->transfers()->whereIn('status', [Transfer::QUEUED, Transfer::RUNNING])->values(),
            $e instanceof TransferFailed ? $e->reason : TransferFailure::Rejected,
        );
    }

    /** @return Collection<int, Transfer> */
    private function transfers(): Collection
    {
        return Transfer::query()
            ->with(['game.files', 'game.media', 'destination'])
            ->whereKey($this->console !== [] ? $this->console : [$this->transferId])
            ->get();
    }

    /** @param  Collection<int, Transfer>  $transfers */
    private function finish(Collection $transfers, ?TransferFailure $failure): void
    {
        foreach ($transfers as $transfer) {
            $transfer->update([
                'status' => $failure === null ? Transfer::DONE : Transfer::FAILED,
                'failure' => $failure,
                'finished_at' => now(),
            ]);

            LiveUpdates::game($transfer->game_id, GameUpdated::TRANSFER);
        }

        $this->signalBatch();
    }

    private function signalBatch(): void
    {
        if ($this->console !== []) {
            LiveUpdates::system(SystemUpdated::TRANSFER);
        }
    }
}
