<?php

namespace App\Models;

use Database\Factories\RaGameHashFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row of the hash index: this file hash belongs to that set.
 *
 * Downloaded a console at a time and ahead of need, so identifying a game
 * costs a local lookup instead of a request. A set lists every hash it knows,
 * which is why one disc of a four-disc game is enough to recognise all of it.
 *
 * @property int $id
 * @property string $hash
 * @property int $ra_game_id
 * @property int $ra_console_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['hash', 'ra_game_id', 'ra_console_id'])]
class RaGameHash extends Model
{
    /** @use HasFactory<RaGameHashFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ra_game_id' => 'integer',
            'ra_console_id' => 'integer',
        ];
    }

    /** @return BelongsTo<RaGame, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(RaGame::class, 'ra_game_id');
    }
}
