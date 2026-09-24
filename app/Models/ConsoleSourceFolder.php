<?php

namespace App\Models;

use App\Support\Console;
use App\Support\Layouts\ConsoleLayout;
use App\Support\Layouts\Layouts;
use App\Support\Scanning\FolderCounts;
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
 * How the folder is arranged is remembered here too, for the same reason: it
 * is a fact about this install of this console, chosen once when it was added,
 * not something to re-derive from the filesystem every time somebody asks.
 *
 * @property int $id
 * @property string $console
 * @property string $path relative to the library root
 * @property string|null $layout null means the console's declared default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['console', 'path', 'layout'])]
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
    public static function add(Console $console, ?string $path = null, ?string $layout = null): self
    {
        $row = static::updateOrCreate(
            ['console' => $console->key],
            [
                'path' => trim($path ?? $console->folder, '/'),
                'layout' => $layout,
            ],
        );

        // The path and the layout both decide which files count, so any cached
        // number taken before this was written is about a different folder.
        FolderCounts::forget($console);

        return $row;
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

        FolderCounts::forget($console);
    }

    public static function pathFor(Console $console): ?string
    {
        $override = static::query()->where('console', $console->key)->value('path');

        if (is_string($override) && $override !== '') {
            return trim($override, '/');
        }

        return $console->folder !== '' ? $console->folder : null;
    }

    /**
     * The layout key stored for this console, or its declared default.
     *
     * The cheap lookup: one indexed read, no filesystem and no config walk. A
     * stored key config no longer carries falls back rather than throwing, so
     * an install that outlives a layout keeps working.
     */
    public static function layoutKeyFor(Console $console): string
    {
        return static::layoutFor($console)->key();
    }

    /**
     * The layout object for this console, for the scanner and the toolbox.
     */
    public static function layoutFor(Console $console): ConsoleLayout
    {
        $stored = static::query()->where('console', $console->key)->value('layout');

        $layout = is_string($stored) && Layouts::supports($console, $stored)
            ? Layouts::make($stored)
            : null;

        return $layout ?? Layouts::default($console);
    }

    /**
     * Where this console's layout reads games from, as folder => label.
     *
     * The folders a file may be put in by hand, by an upload or a move. Read
     * off layoutFor(), so it already falls back to the console's default
     * layout. '' is the console's own folder.
     *
     * @return array<string, string>
     */
    public static function destinationsFor(Console $console): array
    {
        $root = static::pathFor($console) ?? $console->folder;

        return collect(static::layoutFor($console)->gameDirectories())
            ->mapWithKeys(function (string $directory) use ($root): array {
                $directory = trim($directory, '/');

                return [$directory => $directory === '' ? $root.'/' : $root.'/'.$directory.'/'];
            })
            ->all();
    }

    /**
     * Change how an already-added console is read.
     *
     * Silently ignores a layout the console does not offer: the picker only
     * shows supported ones, so anything else arrived by hand.
     */
    public static function setLayout(Console $console, string $layout): void
    {
        if (! Layouts::supports($console, $layout)) {
            return;
        }

        static::query()->where('console', $console->key)->update(['layout' => $layout]);

        // A mass update fires no model events, which is why every one of these
        // clears the count by hand rather than through a saved() hook.
        FolderCounts::forget($console);
    }
}
