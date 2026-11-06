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
use App\Transfers\Endpoints\ShareEndpoint;
use App\Transfers\Location;
use App\Transfers\TransferRejected;
use App\Transfers\TransferTarget;
use App\Transfers\TransferTargets;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
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
 *
 * One game or a thousand: everything here is done a chunk of games at a
 * time, and every question to the share is a listing of a folder rather than
 * one per path. A console's send used to be a single pass over every game at
 * once, which was a library's worth of games and artwork in memory and
 * thousands of SMB round trips, inside a timeout meant for one game.
 */
class WriteTransferGamelist implements ShouldQueue
{
    use Queueable;

    /**
     * A console's list, not one game's: every game of the send passes
     * through here, over a share that answers in tens of milliseconds. Well
     * inside database-long's retry_after, which it has to stay under.
     */
    public int $timeout = 1800;

    public int $tries = 2;

    public int $backoff = 30;

    /**
     * A timeout fails the send then and there, rather than being retried.
     *
     * Without this the worker is killed and the row is left reserved, so
     * nothing happens at all for database-long's two hours before the job is
     * even tried again — and two hours after that it fails in silence. That
     * is what a console's list overrunning the old two-minute timeout looked
     * like from the console page: a send stuck for ever on the last step,
     * every file already copied.
     */
    public bool $failOnTimeout = true;

    /**
     * Games held in memory at once.
     *
     * Each one is read back with its files and all its artwork rows, so a
     * thousand of them at once is tens of megabytes before the list itself —
     * past what PHP allows a worker by default. A hundred at a time also
     * parses the list a dozen times rather than a thousand times, which is
     * what merging game by game would cost (see mergeGamelist).
     */
    private const CHUNK = 100;

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
        // A game whose files never arrived has no entry to write. One its
        // job gave up on is already marked failed; one never picked up is
        // said to have failed here, rather than left waiting for ever.
        $this->eachChunk([Transfer::QUEUED], fn (Collection $stranded) => $this->finish($stranded, TransferFailure::Rejected));

        $first = $this->first();

        if ($first === null || $first->destination === null) {
            $this->signalBatch();

            return;
        }

        $target = TransferTargets::chosen($first->target, $first->options);

        if ($target === null) {
            $this->finishArrived(TransferFailure::Rejected);

            return;
        }

        try {
            $share = $endpoints->resolve(Location::destination($first->destination, '')->endpoint);

            // The folders this job reads, each listed once however many
            // games ask about it, and kept right as it writes and deletes.
            $listed = [];
            $prepared = [];

            $this->eachChunk([Transfer::RUNNING], function (Collection $arrived) use ($share, $target, &$listed, &$prepared): void {
                $this->writeExtras($share, $target, $arrived, $listed, $prepared);
                $this->removeReplaced($share, $target, $arrived, $listed);
            });

            $path = $target->plan($first->game)->gamelist;

            // A system that keeps no list: the files arriving was the send.
            if ($path === null) {
                $this->finishArrived(null);

                return;
            }

            // Read, merged and written under a lock: with several transfer
            // workers, two sends to one box could otherwise both read the
            // list before either wrote it, and one game's entry would go.
            Cache::lock('transfer-gamelist:'.$first->destination->id.':'.$path, 1800)->block(60, function () use ($share, $path, $target): void {
                $gamelist = $share->read($path);

                $this->eachChunk([Transfer::RUNNING], function (Collection $arrived) use (&$gamelist, $target): void {
                    $gamelist = $target->mergeGamelist($gamelist, ...$arrived->pluck('game')->all());
                });

                $share->replace($path, (string) $gamelist);
            });
        } catch (LockTimeoutException) {
            // Another send is writing this list and did not finish in a
            // minute; try again after the backoff rather than write blind.
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff);

                return;
            }

            $this->finishArrived(TransferFailure::Unreachable);

            return;
        } catch (TransferRejected $e) {
            Log::warning('A game list on a share could not be merged.', ['transfer' => $first->id, 'games' => count($this->ids()), 'reason' => $e->getMessage()]);
            $this->finishArrived(TransferFailure::Rejected);

            return;
        } catch (TransferFailed $e) {
            if ($e->reason->retryable() && $this->attempts() < $this->tries) {
                throw $e;
            }

            $this->finishArrived($e->reason);

            return;
        }

        $this->finishArrived(null);
    }

    public function failed(?Throwable $e): void
    {
        $reason = match (true) {
            $e instanceof TransferFailed => $e->reason,
            // Out of its own time, or out of attempts after being released:
            // the share was too slow to finish, which another go may fix.
            $e instanceof TimeoutExceededException, $e instanceof MaxAttemptsExceededException => TransferFailure::Unreachable,
            default => TransferFailure::Rejected,
        };

        $this->eachChunk([Transfer::QUEUED, Transfer::RUNNING], fn (Collection $chunk) => $this->finish($chunk, $reason));
    }

    /**
     * The ids this job was given, in the order they were queued.
     *
     * @return list<int>
     */
    private function ids(): array
    {
        return array_values(array_filter($this->console !== [] ? $this->console : [$this->transferId], fn (?int $id): bool => $id !== null));
    }

    /**
     * The send's rows a chunk at a time, each chunk read fresh with what the
     * target needs of its games. Read by id rather than scanned, so a status
     * this job writes as it goes cannot move the ground under the next chunk.
     *
     * @param  list<string>  $status
     * @param  callable(Collection<int, Transfer>): void  $each
     */
    private function eachChunk(array $status, callable $each): void
    {
        foreach (array_chunk($this->ids(), self::CHUNK) as $ids) {
            $chunk = Transfer::query()
                ->with(['game.files', 'game.media', 'destination'])
                ->whereKey($ids)
                ->whereIn('status', $status)
                ->orderBy('id')
                ->get();

            if ($chunk->isNotEmpty()) {
                $each($chunk);
            }
        }
    }

    /** One row of the send, for the destination and the target they all share. */
    private function first(): ?Transfer
    {
        return Transfer::query()
            ->with(['game.files', 'game.media', 'destination'])
            ->whereKey($this->ids())
            ->where('status', Transfer::RUNNING)
            ->orderBy('id')
            ->first();
    }

    /** Every game whose files are there: done, or failed for the one reason. */
    private function finishArrived(?TransferFailure $failure): void
    {
        $this->eachChunk([Transfer::RUNNING], fn (Collection $arrived) => $this->finish($arrived, $failure));
    }

    /**
     * Each game's extras that the share does not have yet, made now and
     * written; one already there is somebody's, and stays, unless it is one
     * the target rewrites (a generated playlist). Each folder is made once,
     * the first time one of them goes into it.
     *
     * @param  Collection<int, Transfer>  $arrived
     * @param  array<string, array<string, bool>>  $listed
     * @param  list<string>  $prepared
     *
     * @throws TransferFailed
     */
    private function writeExtras(Endpoint $share, TransferTarget $target, Collection $arrived, array &$listed, array &$prepared): void
    {
        foreach ($arrived as $transfer) {
            foreach ($target->extras($transfer->game) as $destination) {
                if (! $target->rewritesExtra($transfer->game, $destination) && $this->isThere($share, $destination, $listed)) {
                    continue;
                }

                $bytes = $target->extra($transfer->game, $destination);

                if ($bytes === null) {
                    continue;
                }

                $folder = $this->folderOf($destination);

                if (! in_array($folder, $prepared, true)) {
                    $share->prepare($folder);
                    $prepared[] = $folder;
                }

                $share->replace($destination, $bytes);

                // Into a listing that has been read, and no other: a
                // playlist is written without isThere() being asked —
                // rewritesExtra() is always true for one — so a name set on
                // a folder nobody has listed would stand in for the listing
                // and everything already there would read as absent.
                if (isset($listed[$folder])) {
                    $listed[$folder][basename($destination)] = true;
                }
            }
        }
    }

    /**
     * The games' other versions, where they are on the share: removed now
     * that these have arrived, and their entries go from the list in the
     * merge that follows, so the front-end lists each game once. A path not
     * there is nothing to do.
     *
     * @param  Collection<int, Transfer>  $arrived
     * @param  array<string, array<string, bool>>  $listed
     *
     * @throws TransferFailed
     */
    private function removeReplaced(Endpoint $share, TransferTarget $target, Collection $arrived, array &$listed): void
    {
        foreach ($arrived as $transfer) {
            try {
                $replaces = $target->plan($transfer->game)->replaces;
            } catch (TransferRejected) {
                continue;
            }

            foreach ($replaces as $path) {
                if ($this->isThere($share, $path, $listed)) {
                    $share->delete($path);
                    unset($listed[$this->folderOf($path)][basename($path)]);
                }
            }
        }
    }

    /**
     * Whether a path is on the share, asked of a listing of its folder
     * rather than of the path itself.
     *
     * A game of several versions names a dozen paths that are usually not
     * there — every other version's files, in both arrangements, and every
     * artwork slot of each — and a console's worth of them was a question
     * apiece: thousands of round trips. The names of a folder come in one,
     * and are what a copy's arrival means anyway (planConsole does the same).
     *
     * @param  array<string, array<string, bool>>  $listed
     *
     * @throws TransferFailed
     */
    private function isThere(Endpoint $share, string $path, array &$listed): bool
    {
        if (! $share instanceof ShareEndpoint) {
            return $share->sizeOf($path) !== null;
        }

        $folder = $this->folderOf($path);
        $listed[$folder] ??= array_fill_keys($share->namesIn($folder), true);

        return isset($listed[$folder][basename($path)]);
    }

    /** The folder a path is in, '' for the destination's own. */
    private function folderOf(string $path): string
    {
        return dirname($path) === '.' ? '' : dirname($path);
    }

    /**
     * @param  Collection<int, Transfer>  $transfers
     */
    private function finish(Collection $transfers, ?TransferFailure $failure): void
    {
        if ($transfers->isNotEmpty()) {
            Transfer::query()->whereKey($transfers->modelKeys())->update([
                'status' => $failure === null ? Transfer::DONE : Transfer::FAILED,
                'failure' => $failure,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            // A game is not done until its entry is in the list, and this
            // is where that is said. A game page open through a console's
            // send waits on its own game's signal and reads nothing else,
            // so a console's games have to be told as well as one sent from
            // its page — FileTransferJob says the same as it copies, and
            // signalBatch() is what the console page follows.
            foreach ($transfers as $transfer) {
                LiveUpdates::game($transfer->game_id, GameUpdated::TRANSFER);
            }
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
