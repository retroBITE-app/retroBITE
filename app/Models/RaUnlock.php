<?php

namespace App\Models;

use Database\Factories\RaUnlockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * That a person has an achievement.
 *
 * One row per person and achievement, holding two dates rather than a hardcore
 * flag. A flag would allow the same achievement twice for one person, saying
 * contradictory things; two nullable columns cannot.
 *
 * @property int $id
 * @property int $user_id
 * @property int $ra_achievement_id
 * @property int $ra_game_id
 * @property Carbon|null $unlocked_at
 * @property Carbon|null $unlocked_hardcore_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'ra_achievement_id', 'ra_game_id', 'unlocked_at', 'unlocked_hardcore_at'])]
class RaUnlock extends Model
{
    /** @use HasFactory<RaUnlockFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'ra_achievement_id' => 'integer',
            'ra_game_id' => 'integer',
            'unlocked_at' => 'datetime',
            'unlocked_hardcore_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<RaAchievement, $this> */
    public function achievement(): BelongsTo
    {
        return $this->belongsTo(RaAchievement::class, 'ra_achievement_id');
    }

    /** @return BelongsTo<RaGame, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(RaGame::class, 'ra_game_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
