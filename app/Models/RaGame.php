<?php

namespace App\Models;

use Database\Factories\RaGameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One achievement set: everything RetroAchievements defines for a title.
 *
 * Not a Game. The same set answers for every region and every disc of a title,
 * it belongs to RetroAchievements rather than to us, and it can be deleted and
 * fetched again without anything being lost. A Game points at one of these;
 * unlocks and progress hang off this, so that merging or rebuilding the
 * library cannot take somebody's history with it.
 *
 * @property int $id
 * @property int $ra_console_id
 * @property string $title
 * @property string|null $image_icon
 * @property int $num_achievements
 * @property int $points_total
 * @property int $num_distinct_players
 * @property int $num_distinct_players_hardcore
 * @property Carbon|null $set_synced_at
 * @property Carbon|null $set_updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, RaAchievement> $achievements
 * @property-read Collection<int, RaGameHash> $hashes
 */
#[Fillable([
    // 'id' is listed because it is RetroAchievements' own id rather than an
    // auto-increment. updateOrCreate fills a new model through fill(), and a
    // key left out of this list is dropped in silence — every row would insert
    // as id 0 and the second would collide with the first.
    'id', 'ra_console_id', 'title', 'image_icon', 'num_achievements', 'points_total',
    'num_distinct_players', 'num_distinct_players_hardcore', 'set_synced_at', 'set_updated_at',
])]
class RaGame extends Model
{
    /** @use HasFactory<RaGameFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'int';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'ra_console_id' => 'integer',
            'num_achievements' => 'integer',
            'points_total' => 'integer',
            'num_distinct_players' => 'integer',
            'num_distinct_players_hardcore' => 'integer',
            'set_synced_at' => 'datetime',
            'set_updated_at' => 'datetime',
        ];
    }

    /** @return HasMany<RaAchievement, $this> */
    public function achievements(): HasMany
    {
        return $this->hasMany(RaAchievement::class);
    }

    /** @return HasMany<RaGameHash, $this> */
    public function hashes(): HasMany
    {
        return $this->hasMany(RaGameHash::class);
    }

    /** @return HasMany<RaProgress, $this> */
    public function progress(): HasMany
    {
        return $this->hasMany(RaProgress::class);
    }

    /** @return HasMany<Game, $this> */
    public function games(): HasMany
    {
        return $this->hasMany(Game::class, 'retroachievements_id');
    }
}
