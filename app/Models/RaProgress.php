<?php

namespace App\Models;

use App\Enums\AwardKind;
use Database\Factories\RaProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How far one person has got in one set, counted up in advance.
 *
 * Denormalised on purpose. The library list shows progress on every card, and
 * a page of twenty-four cards must not mean twenty-four aggregations — so the
 * counters live here and the list is a single join.
 *
 * Only the progress sync writes the counters, and it recomputes them from the
 * unlock rows inside the transaction that writes them. Nothing else may touch
 * them, or two sources of truth start disagreeing quietly.
 *
 * @property int $id
 * @property int $user_id
 * @property int $ra_game_id
 * @property int $unlocked_count
 * @property int $unlocked_hardcore_count
 * @property int $achievements_possible
 * @property int $points_earned
 * @property int $points_hardcore_earned
 * @property int $points_possible
 * @property AwardKind|null $highest_award_kind
 * @property Carbon|null $highest_award_at
 * @property int|null $site_rank
 * @property int|null $site_score
 * @property Carbon|null $last_unlock_at
 * @property Carbon|null $synced_at
 * @property bool $stale
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id', 'ra_game_id', 'unlocked_count', 'unlocked_hardcore_count',
    'achievements_possible', 'points_earned', 'points_hardcore_earned', 'points_possible',
    'highest_award_kind', 'highest_award_at', 'site_rank', 'site_score',
    'last_unlock_at', 'synced_at', 'stale',
])]
class RaProgress extends Model
{
    /** @use HasFactory<RaProgressFactory> */
    use HasFactory;

    protected $table = 'ra_progress';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'ra_game_id' => 'integer',
            'unlocked_count' => 'integer',
            'unlocked_hardcore_count' => 'integer',
            'achievements_possible' => 'integer',
            'points_earned' => 'integer',
            'points_hardcore_earned' => 'integer',
            'points_possible' => 'integer',
            'highest_award_kind' => AwardKind::class,
            'highest_award_at' => 'datetime',
            'site_rank' => 'integer',
            'site_score' => 'integer',
            'last_unlock_at' => 'datetime',
            'synced_at' => 'datetime',
            'stale' => 'boolean',
        ];
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
