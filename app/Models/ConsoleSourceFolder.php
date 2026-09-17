<?php

namespace App\Models;

use App\Support\Console;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Where a console's ROMs live, when they are not where convention says.
 *
 * Convention comes first: a console's files are expected at
 * games_path/{folder}. A row here exists only when that directory was missing
 * and somebody pointed at a different one — so an empty table is the normal,
 * healthy state, not an unconfigured one.
 *
 * @property int $id
 * @property string $console
 * @property string $path relative to the library root
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['console', 'path'])]
class ConsoleSourceFolder extends Model
{
    /**
     * The scan root for a console: the override if one is set, else convention.
     *
     * Returns a path relative to the library root, which is what files.path is
     * stored against.
     */
    public static function pathFor(Console $console): ?string
    {
        $override = static::query()->where('console', $console->key)->value('path');

        if (is_string($override) && $override !== '') {
            return trim($override, '/');
        }

        return $console->folder !== '' ? $console->folder : null;
    }
}
