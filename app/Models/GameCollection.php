<?php

namespace App\Models;

use Database\Factories\GameCollectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A list of games somebody put together by hand.
 *
 * Not a series and not an export target: "Mega Man" as a franchise is provider
 * metadata, and "what goes on the OPL stick" is a different concept still.
 * This is only ever what a person chose to group.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Game> $games
 */
#[Fillable(['name', 'slug', 'description'])]
class GameCollection extends Model
{
    /** @use HasFactory<GameCollectionFactory> */
    use HasFactory;

    /** @return BelongsToMany<Game, $this> */
    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class, 'game_collection_game')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }
}
