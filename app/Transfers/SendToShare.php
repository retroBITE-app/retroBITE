<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Enums\TransferFailure;
use App\Enums\TransferMode;
use App\Events\SystemUpdated;
use App\Exceptions\TransferFailed;
use App\Jobs\FileTransferJob;
use App\Jobs\PlanConsoleTransfer;
use App\Jobs\WriteTransferGamelist;
use App\Models\Destination;
use App\Models\Game;
use App\Models\Transfer;
use App\Support\LiveUpdates;
use App\Transfers\Endpoints\Endpoints;
use App\Transfers\Endpoints\ShareEndpoint;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

/**
 * Send a game, or a whole console, to a network share, laid out for a
 * transfer target.
 *
 * The target's plan says what goes where; FileTransferJob copies it, and
 * WriteTransferGamelist adds the games to the list after. Always a copy: the
 * library keeps its files.
 */
final class SendToShare
{
    /** @throws TransferRejected */
    public function send(Game $game, TransferTarget $target, Destination $destination): Transfer
    {
        $plan = $target->plan($game);

        if ($plan->files === []) {
            throw new TransferRejected(__('This game has no files to send.'));
        }

        $transfer = $this->record($game, $target, $destination, $plan);

        Bus::chain([
            new FileTransferJob($this->copies($plan, $destination), TransferMode::Copy, $transfer->id),
            new WriteTransferGamelist($transfer->id),
        ])->onConnection('database-long')->onQueue('transfer')->dispatch();

        return $transfer;
    }

    /**
     * Every identified game of a console, one version each.
     *
     * Only the rows are made here, so the page gets its answer at once; what
     * to copy is worked out on the queue by PlanConsoleTransfer, which asks
     * the share what it already holds — a page never waits on the network.
     *
     * @return array{batch: string, games: int, rejected: int}
     *
     * @throws TransferRejected when there is nothing to send
     */
    public function sendConsole(string $console, TransferTarget $target, Destination $destination): array
    {
        ['games' => $games, 'plans' => $plans, 'rejected' => $rejected] = ConsoleTransfer::plan($target, $console);

        if ($games === []) {
            throw new TransferRejected(__('No identified game on this console has files to send.'));
        }

        // The id the console's page follows the send by, on every row.
        $sendId = (string) Str::uuid();
        $transfers = [];

        foreach ($games as $game) {
            $transfers[] = $this->record($game, $target, $destination, $plans[$game->id], $sendId)->id;
        }

        PlanConsoleTransfer::dispatch($transfers);

        return ['batch' => $sendId, 'games' => count($games), 'rejected' => $rejected];
    }

    /**
     * Queue the copies a console send still needs, after making and listing
     * each folder it writes to, once.
     *
     * A game whose every file is already on the share at its size is copied
     * already: counted as done, with no job at all, so a send run again
     * queues only what is missing and the page says so. The rest go one
     * FileTransferJob per game, not one for the console — a transfer goes
     * all or nothing, and one game's missing file should not undo the
     * hundred copied before it — in a batch that carries on past a game that
     * fails. The game list is written once at the end, for every game whose
     * files are there, the ones that were there already included.
     *
     * @param  list<int>  $transferIds
     *
     * @throws TransferFailed when the share cannot be listed
     */
    public function planConsole(array $transferIds, Endpoints $endpoints): void
    {
        $transfers = Transfer::query()->with(['game.files', 'game.media', 'destination'])->whereKey($transferIds)->get();
        $first = $transfers->first();

        if ($first === null || $first->destination === null || ($target = TransferTargets::find($first->target)) === null) {
            return;
        }

        $share = $endpoints->resolve(Location::destination($first->destination, '')->endpoint);
        $listed = [];
        $jobs = [];

        foreach ($transfers as $transfer) {
            try {
                $plan = $target->plan($transfer->game);
            } catch (TransferRejected) {
                $transfer->update(['status' => Transfer::FAILED, 'failure' => TransferFailure::Rejected, 'finished_at' => now()]);

                continue;
            }

            // Each folder the send writes to is made, proven writable and
            // listed once, the first time a game needs it — not once per
            // game, which was a third of the time a small game took. A name
            // there is a whole file: copies arrive under a temporary name and
            // take their own only once complete.
            $missing = array_filter($plan->files, function (PlannedFile $file) use ($share, &$listed): bool {
                $folder = dirname($file->destination) === '.' ? '' : dirname($file->destination);

                if (! isset($listed[$folder])) {
                    $share->prepare($folder);
                    $listed[$folder] = $share instanceof ShareEndpoint ? array_flip($share->namesIn($folder)) : [];
                }

                return ! isset($listed[$folder][basename($file->destination)]);
            });

            if ($missing === []) {
                $transfer->update([
                    'status' => Transfer::RUNNING,
                    'files_total' => count($plan->files),
                    'files_done' => count($plan->files),
                    'files_skipped' => count($plan->files),
                ]);

                continue;
            }

            $jobs[] = new FileTransferJob($this->copies($plan, $first->destination), TransferMode::Copy, $transfer->id, prepared: true);
        }

        LiveUpdates::system(SystemUpdated::TRANSFER);

        $gamelist = WriteTransferGamelist::forConsole($transferIds);

        if ($jobs === []) {
            dispatch($gamelist);

            return;
        }

        Bus::batch($jobs)
            ->name('Send a console to '.$first->destination->name)
            ->allowFailures()
            ->finally(function (Batch $batch) use ($transferIds): void {
                dispatch(WriteTransferGamelist::forConsole($transferIds));
            })
            ->onConnection('database-long')
            ->onQueue('transfer')
            ->dispatch();
    }

    private function record(Game $game, TransferTarget $target, Destination $destination, TransferPlan $plan, ?string $sendId = null): Transfer
    {
        return Transfer::query()->create([
            'game_id' => $game->id,
            'destination_id' => $destination->id,
            'target' => $target->key(),
            'batch_id' => $sendId,
            'status' => Transfer::QUEUED,
            'files_total' => count($plan->files),
            'bytes_total' => $plan->bytes(),
        ]);
    }

    /** @return list<FileTransfer> */
    private function copies(TransferPlan $plan, Destination $destination): array
    {
        return array_map(
            fn (PlannedFile $file): FileTransfer => new FileTransfer($file->source, Location::destination($destination, $file->destination)),
            $plan->files,
        );
    }
}
