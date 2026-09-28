<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Enums\TransferMode;
use App\Jobs\FileTransferJob;
use App\Jobs\WriteTransferGamelist;
use App\Models\Destination;
use App\Models\Game;
use App\Models\Transfer;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

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
     * One FileTransferJob per game, not one for the console: a transfer goes
     * all or nothing, and one game's missing file should not undo the
     * hundred copied before it. They run as a batch that carries on past a
     * game that fails, and the game list is written once at the end, for
     * every game whose files arrived — not a console's worth of reads and
     * writes of one growing file.
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

        // In one transaction, so no worker picks up a copy before its row
        // knows which batch it belongs to.
        $batchId = DB::transaction(function () use ($games, $plans, $target, $destination): string {
            $jobs = [];
            $transfers = [];

            foreach ($games as $game) {
                $plan = $plans[$game->id];
                $transfer = $this->record($game, $target, $destination, $plan);
                $transfers[] = $transfer->id;
                $jobs[] = new FileTransferJob($this->copies($plan, $destination), TransferMode::Copy, $transfer->id);
            }

            // The rows are handed over by id rather than looked up by batch:
            // run inline, the batch is through before its id is known.
            $batch = Bus::batch($jobs)
                ->name('Send a console to '.$destination->name)
                ->allowFailures()
                ->finally(function (Batch $batch) use ($transfers): void {
                    dispatch(WriteTransferGamelist::forConsole($transfers));
                })
                ->onConnection('database-long')
                ->onQueue('transfer')
                ->dispatch();

            Transfer::query()->whereKey($transfers)->update(['batch_id' => $batch->id]);

            return $batch->id;
        });

        return ['batch' => $batchId, 'games' => count($games), 'rejected' => $rejected];
    }

    private function record(Game $game, TransferTarget $target, Destination $destination, TransferPlan $plan): Transfer
    {
        return Transfer::query()->create([
            'game_id' => $game->id,
            'destination_id' => $destination->id,
            'target' => $target->key(),
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
