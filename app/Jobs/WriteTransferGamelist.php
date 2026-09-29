<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TransferFailure;
use App\Events\GameUpdated;
use App\Events\SystemUpdated;
use App\Exceptions\TransferFailed;
use App\Models\Transfer;
use App\Support\LiveUpdates;
use App\Transfers\Endpoints\Endpoint;
use App\Transfers\Endpoints\Endpoints;
use App\Transfers\Location;
use App\Transfers\TransferRejected;
use App\Transfers\TransferTarget;
use App\Transfers\TransferTargets;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The last step of sending to a share: the target's own files for each game
 * (TransferTarget::extras(), an OPL config and art), then the games' entries
 * in the target's game list, merged into whatever list is there. It only runs
 * once the files have arrived — chained after one game's FileTransferJob, or
 * run once a console's batch is through, for every game in it whose files
 * arrived.
 *
 * Not a file transfer — these are made and written, not copied. An extra
 * already on the share is left as it is; the list is the one write to a
 * destination that replaces a file. Everyone else's entries stay as they
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
            $share = $endpoints->resolve(Location::destination($first->destination, '')->endpoint);

            $this->writeExtras($share, $target, $arrived);

            $path = $target->plan($first->game)->gamelist;

            // A system that keeps no list: the files arriving was the send.
            if ($path === null) {
                $this->finish($arrived, null);

                return;
            }

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

    /**
     * Each game's extras that the share does not have yet, made now and
     * written; one already there is somebody's, and stays. Each folder is
     * made once, the first time one of them goes into it.
     *
     * @param  Collection<int, Transfer>  $arrived
     *
     * @throws TransferFailed
     */
    private function writeExtras(Endpoint $share, TransferTarget $target, Collection $arrived): void
    {
        $prepared = [];

        foreach ($arrived as $transfer) {
            foreach ($target->extras($transfer->game) as $destination) {
                if ($share->sizeOf($destination) !== null) {
                    continue;
                }

                $bytes = $target->extra($transfer->game, $destination);

                if ($bytes === null) {
                    continue;
                }

                $folder = dirname($destination) === '.' ? '' : dirname($destination);

                if (! in_array($folder, $prepared, true)) {
                    $share->prepare($folder);
                    $prepared[] = $folder;
                }

                $share->replace($destination, $bytes);
            }
        }
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
