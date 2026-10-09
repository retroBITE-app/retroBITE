<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One game as the LaunchBox Games Database lists it, kept for its rating.
 *
 * Not a Game, and nothing here is ours: LaunchBoxIndex rebuilds these rows a
 * platform at a time from the public dump, and a Game points at one through
 * launchbox_id. The id is LaunchBox's own DatabaseID.
 *
 * @property int $id
 * @property string $platform
 * @property string $name
 * @property float|null $rating the players' average, out of five; null when nobody voted
 * @property int $votes
 */
#[Fillable(['id', 'platform', 'name', 'rating', 'votes'])]
class LaunchBoxGame extends Model
{
    protected $table = 'launchbox_games';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'int';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'rating' => 'float',
            'votes' => 'integer',
        ];
    }
}
