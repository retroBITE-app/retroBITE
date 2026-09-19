<?php

namespace App\Models;

use App\Enums\AchievementKind;
use Database\Factories\RaAchievementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One achievement in a set.
 *
 * @property int $id
 * @property int $ra_game_id
 * @property string $title
 * @property string|null $description
 * @property int $points
 * @property int $true_ratio
 * @property string|null $badge_name
 * @property AchievementKind|null $kind
 * @property int $display_order
 * @property bool $core
 * @property int $num_awarded
 * @property int $num_awarded_hardcore
 * @property Carbon|null $removed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    /* 'id' for the same reason as RaGame: it is the provider's, not ours. */
    'id', 'ra_game_id', 'title', 'description', 'points', 'true_ratio', 'badge_name',
    'kind', 'display_order', 'core', 'num_awarded', 'num_awarded_hardcore', 'removed_at',
])]
class RaAchievement extends Model
{
    /** @use HasFactory<RaAchievementFactory> */
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
            'ra_game_id' => 'integer',
            'kind' => AchievementKind::class,
            'points' => 'integer',
            'true_ratio' => 'integer',
            'display_order' => 'integer',
            'core' => 'boolean',
            'num_awarded' => 'integer',
            'num_awarded_hardcore' => 'integer',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * The badge image, built here and nowhere else.
     *
     * Only badge_name is stored, so moving these to local storage later is a
     * change to this method rather than a re-sync of every set. This is also
     * the one part of the achievement data that needs the network at render
     * time: offline, the page is correct and the badges are broken images.
     */
    public function badgeUrl(bool $locked = false): ?string
    {
        if ($this->badge_name === null || $this->badge_name === '') {
            return null;
        }

        $base = rtrim((string) config('retroachievements.media_url'), '/');

        return $base.'/Badge/'.$this->badge_name.($locked ? '_lock' : '').'.png';
    }

    /**
     * How many of the people who played this game have it, as a percentage.
     *
     * Null rather than zero when nobody has played it: "0% of players" and
     * "we do not know" are different things, and only one of them is worth
     * putting on screen.
     */
    public function rarity(): ?float
    {
        $players = $this->relationLoaded('game') ? (int) $this->game?->num_distinct_players : 0;

        if ($players <= 0) {
            return null;
        }

        return round($this->num_awarded / $players * 100, 1);
    }

    /** @return BelongsTo<RaGame, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(RaGame::class, 'ra_game_id');
    }

    /** @return HasMany<RaUnlock, $this> */
    public function unlocks(): HasMany
    {
        return $this->hasMany(RaUnlock::class, 'ra_achievement_id');
    }

    /**
     * The achievements that count towards a score.
     *
     * Unofficial ones are stored but never counted, and a demoted one stops
     * counting without its unlock row being touched — which is how a past
     * mastery keeps agreeing with what the site says.
     *
     * @param  Builder<RaAchievement>  $query
     */
    public function scopeCounting(Builder $query): void
    {
        $query->where('core', true)->whereNull('removed_at');
    }
}
