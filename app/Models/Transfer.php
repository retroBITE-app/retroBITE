<?php

namespace App\Models;

use App\Enums\TransferFailure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One game sent to one network destination, for one transfer target.
 *
 * Its files are copied by FileTransferJob, and its game list is written by
 * WriteTransferGamelist after them. Kept so the game page can say how it went.
 * A console sent at once is one of these per game, sharing a batch_id.
 *
 * @property int $id
 * @property int $game_id
 * @property int|null $destination_id
 * @property string $target
 * @property string|null $batch_id the send a console sent at once shares
 * @property string $status queued | running | done | failed
 * @property int $files_total
 * @property int $files_done
 * @property int $files_skipped
 * @property int $bytes_total
 * @property TransferFailure|null $failure
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Game $game
 * @property-read Destination|null $destination
 */
#[Fillable(['game_id', 'destination_id', 'target', 'batch_id', 'status', 'files_total', 'files_done', 'files_skipped', 'bytes_total', 'failure', 'finished_at'])]
class Transfer extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'failure' => TransferFailure::class,
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return BelongsTo<Destination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::DONE, self::FAILED], true);
    }
}
