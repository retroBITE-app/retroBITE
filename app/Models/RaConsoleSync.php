<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * When a console's hash index was last downloaded, and how much of it there was.
 *
 * Derivable from max(updated_at) on the hashes, except for the console that
 * came back empty — which is the one case worth being able to tell apart from
 * "never asked".
 *
 * @property int $id
 * @property int $ra_console_id
 * @property Carbon|null $synced_at
 * @property int $games
 * @property int $hashes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['ra_console_id', 'synced_at', 'games', 'hashes'])]
class RaConsoleSync extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ra_console_id' => 'integer',
            'synced_at' => 'datetime',
            'games' => 'integer',
            'hashes' => 'integer',
        ];
    }
}
