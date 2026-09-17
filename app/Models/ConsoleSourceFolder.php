<?php

namespace App\Models;

use App\Support\Console;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A console somebody put in their library, and where its files are.
 *
 * A row is what makes a console appear at all. It deliberately does not follow
 * from a directory existing: a ROM collection copied wholesale can leave a
 * hundred folders on disk with games in four of them, and listing all hundred
 * buries the four. Being in the library is a choice, not a side effect of the
 * filesystem.
 *
 * The path is usually the conventional games_path/{folder}, filled in without
 * asking when that directory is already there. It differs only when somebody
 * pointed the console somewhere else.
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
    /**
     * The consoles in the library, in the config's own order.
     *
     * @return Collection<int, Console>
     */
    public static function consoles(): Collection
    {
        $keys = static::query()->pluck('path', 'console');

        return Console::all()
            ->filter(fn (Console $console) => $keys->has($console->key))
            ->values();
    }

    public static function has(Console $console): bool
    {
        return static::query()->where('console', $console->key)->exists();
    }

    /**
     * Put a console in the library.
     *
     * A null path means "wherever convention says", which is the ordinary case
     * and the reason adding a console usually asks nothing.
     */
    public static function add(Console $console, ?string $path = null): self
    {
        return static::updateOrCreate(
            ['console' => $console->key],
            ['path' => trim($path ?? $console->folder, '/')],
        );
    }

    /**
     * Take a console out of the library.
     *
     * Its games stay. Nothing on disk is touched — this is a decision about
     * what to look at, not about what to keep.
     */
    public static function forget(Console $console): void
    {
        static::query()->where('console', $console->key)->delete();
    }

    public static function pathFor(Console $console): ?string
    {
        $override = static::query()->where('console', $console->key)->value('path');

        if (is_string($override) && $override !== '') {
            return trim($override, '/');
        }

        return $console->folder !== '' ? $console->folder : null;
    }
}
