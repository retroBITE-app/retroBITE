<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TransferFailure;
use App\Enums\TransferMode;
use App\Events\GameUpdated;
use App\Events\SystemUpdated;
use App\Exceptions\TransferFailed;
use App\Models\Transfer;
use App\Support\LiveUpdates;
use App\Transfers\Endpoints\Endpoint;
use App\Transfers\Endpoints\Endpoints;
use App\Transfers\FileTransfer;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one thing that moves or copies a file on the server.
 *
 * Takes a list rather than a file, because a game is several files that only
 * work together: a cuesheet names its tracks, a playlist its discs. So the
 * list goes all or nothing —
 *
 * 1. everything is checked before anything is written: each source a plain,
 *    readable file; each destination folder made and proven writable with a
 *    probe; room enough where the free space can be read; no name twice;
 * 2. nothing is written over. A copy finding the same name at the same size
 *    skips it, so a transfer that stopped part way resumes; any other clash
 *    refuses the list. A move refuses on any clash;
 * 3. each file is written under a temporary name and renamed once its size
 *    checks out — or, moving within one disk, simply renamed;
 * 4. a failure removes the copies made and moves back the files moved, and a
 *    moved file's source is only deleted once every copy is in place.
 *
 * Queued on `transfer` over database-long, because a disc image to a share
 * takes minutes. Moves inside the library are renames and finish at once, so
 * the game page and the uploader run it in the request with {@see now()} and
 * keep their answer. See docs/adr/0004-one-job-moves-files.md.
 */
class FileTransferJob implements ShouldQueue
{
    // One game of a console sent at once runs in a batch, which carries on
    // past a game that fails; see SendToShare::sendConsole().
    use Batchable, Queueable;

    /** A four-gigabyte disc over a slow wireless link. */
    public int $timeout = 3600;

    /** Only a share that stopped answering is worth a second go; see handle(). */
    public int $tries = 2;

    public int $backoff = 60;

    /**
     * @param  list<FileTransfer>  $transfers
     * @param  int|null  $transferId  the Transfer row to report to, when a person is watching one
     */
    public function __construct(
        public readonly array $transfers,
        public readonly TransferMode $mode = TransferMode::Copy,
        public readonly ?int $transferId = null,
    ) {
        $this->onConnection('database-long')->onQueue('transfer');
    }

    /**
     * Run it here and now, in the request, and throw its refusal to the caller.
     *
     * @param  list<FileTransfer>  $transfers
     *
     * @throws TransferFailed
     */
    public static function now(array $transfers, TransferMode $mode): void
    {
        app()->call([new self($transfers, $mode), 'handle']);
    }

    /** @throws TransferFailed */
    public function handle(Endpoints $endpoints): void
    {
        // Counted again from nothing: a second attempt skips what the first copied.
        $this->report(['status' => Transfer::RUNNING, 'files_done' => 0, 'files_skipped' => 0]);

        try {
            $this->run($endpoints);
        } catch (TransferFailed $e) {
            Log::warning('A file transfer was refused.', [
                'reason' => $e->reason->value,
                'path' => $e->path,
                'previous' => $e->getPrevious()?->getMessage(),
            ]);

            // In the request: the caller answers the person.
            if ($this->job === null) {
                throw $e;
            }

            if ($e->reason->retryable() && $this->attempts() < $this->tries) {
                throw $e;
            }

            $this->finish($e->reason);
            $this->fail($e);
        }
    }

    /** Timed out, or failed some way handle() did not see coming. */
    public function failed(?Throwable $e): void
    {
        $this->finish($e instanceof TransferFailed ? $e->reason : TransferFailure::Rejected);
    }

    /** @throws TransferFailed */
    private function run(Endpoints $endpoints): void
    {
        $plan = $this->check($endpoints);
        $done = [];

        try {
            foreach ($plan as $step) {
                if ($step['skip']) {
                    $this->progress(skipped: true);

                    continue;
                }

                if ($step['adopt']) {
                    $step['to']->adopt($step['from'], $step['transfer']->from->path, $step['transfer']->to->path);
                } else {
                    $step['to']->receive($step['local'], $step['transfer']->to->path, $step['size']);
                }

                $done[] = $step;
                $this->progress();
            }
        } catch (TransferFailed $e) {
            $this->undo($done);

            throw $e;
        }

        if ($this->mode === TransferMode::Move) {
            $this->removeSources($done);
        }
    }

    /**
     * Every check, before anything is written.
     *
     * @return list<array{transfer: FileTransfer, from: Endpoint, to: Endpoint, local: string, size: int, skip: bool, adopt: bool}>
     *
     * @throws TransferFailed
     */
    private function check(Endpoints $endpoints): array
    {
        $plan = [];
        $taken = [];
        $needed = [];
        $folders = [];

        foreach ($this->transfers as $transfer) {
            $from = $endpoints->resolve($transfer->from->endpoint);
            $to = $endpoints->resolve($transfer->to->endpoint);
            $local = $from->localFile($transfer->from->path);
            $size = (int) filesize($local);
            $destination = (string) $transfer->to;

            if (isset($taken[$destination])) {
                throw TransferFailed::because(TransferFailure::Exists, $transfer->to->path);
            }

            $taken[$destination] = true;
            $existing = $to->sizeOf($transfer->to->path);
            $skip = false;

            if ($existing !== null) {
                if ($this->mode !== TransferMode::Copy || $existing !== $size) {
                    throw TransferFailed::because(TransferFailure::Exists, $transfer->to->path);
                }

                $skip = true;
            }

            $adopt = ! $skip && $this->mode === TransferMode::Move && $to->canAdopt($from);

            if (! $skip && ! $adopt) {
                $needed[$transfer->to->endpoint] = ($needed[$transfer->to->endpoint] ?? 0) + $size;
            }

            if (! $skip) {
                $folder = dirname($transfer->to->path);
                $folders[$transfer->to->endpoint][$folder === '.' ? '' : $folder] = $to;
            }

            $plan[] = compact('transfer', 'from', 'to', 'local', 'size', 'skip', 'adopt');
        }

        foreach ($needed as $key => $bytes) {
            $free = $endpoints->resolve($key)->freeBytes();

            if ($free !== null && $free < $bytes) {
                throw TransferFailed::because(TransferFailure::NoSpace, $key);
            }
        }

        foreach ($folders as $byFolder) {
            foreach ($byFolder as $folder => $endpoint) {
                $endpoint->prepare((string) $folder);
            }
        }

        return $plan;
    }

    /**
     * Take back what this run wrote, last first. Logged rather than thrown:
     * the run is already failing, and the reason it failed is the one to tell.
     *
     * @param  list<array{transfer: FileTransfer, from: Endpoint, to: Endpoint, local: string, size: int, skip: bool, adopt: bool}>  $done
     */
    private function undo(array $done): void
    {
        foreach (array_reverse($done) as $step) {
            try {
                if (! $step['adopt']) {
                    $step['to']->delete($step['transfer']->to->path);
                } elseif ($step['from']->canAdopt($step['to'])) {
                    $step['from']->adopt($step['to'], $step['transfer']->to->path, $step['transfer']->from->path);
                } else {
                    Log::error('A moved file cannot be moved back.', ['to' => (string) $step['transfer']->to]);
                }
            } catch (TransferFailed $e) {
                Log::error('A transfer could not be undone.', ['to' => (string) $step['transfer']->to, 'reason' => $e->reason->value]);
            }
        }
    }

    /**
     * After a move by copy, the originals go. A source that will not delete is
     * logged, not thrown: two copies is a nuisance, and none would be a loss.
     *
     * @param  list<array{transfer: FileTransfer, from: Endpoint, to: Endpoint, local: string, size: int, skip: bool, adopt: bool}>  $done
     */
    private function removeSources(array $done): void
    {
        foreach ($done as $step) {
            if ($step['adopt']) {
                continue;
            }

            try {
                $step['from']->delete($step['transfer']->from->path);
            } catch (TransferFailed $e) {
                Log::warning('A moved file was copied but its original stayed.', ['from' => (string) $step['transfer']->from]);
            }
        }
    }

    private function progress(bool $skipped = false): void
    {
        $transfer = $this->transfer();

        if ($transfer === null) {
            return;
        }

        $transfer->increment('files_done');

        if ($skipped) {
            $transfer->increment('files_skipped');
        }

        $this->signal($transfer);
    }

    private function finish(TransferFailure $failure): void
    {
        $this->report(['status' => Transfer::FAILED, 'failure' => $failure, 'finished_at' => now()]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function report(array $attributes): void
    {
        $transfer = $this->transfer();

        if ($transfer === null) {
            return;
        }

        $transfer->update($attributes);

        $this->signal($transfer);
    }

    /** The game's page follows its own transfer; a console's page, its batch. */
    private function signal(Transfer $transfer): void
    {
        LiveUpdates::game($transfer->game_id, GameUpdated::TRANSFER);

        if ($transfer->batch_id !== null) {
            LiveUpdates::system(SystemUpdated::TRANSFER);
        }
    }

    private function transfer(): ?Transfer
    {
        return $this->transferId !== null ? Transfer::query()->find($this->transferId) : null;
    }
}
