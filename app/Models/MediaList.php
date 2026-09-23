<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The artwork the provider offers for a game, as its last answer listed it.
 *
 * Not Media: nothing here has been downloaded. It is the menu the artwork job
 * chooses from, kept so that choosing again costs no lookup.
 *
 * @property int $id
 * @property int $game_id
 * @property array<int, array<string, mixed>> $medias credentials stripped
 * @property Carbon $fetched_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Game $game
 */
#[Fillable(['game_id', 'medias', 'fetched_at'])]
class MediaList extends Model
{
    protected function casts(): array
    {
        return [
            'medias' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
