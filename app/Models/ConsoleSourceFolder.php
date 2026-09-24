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
 * @property int|null $file_count playable files at the last count, null before the first
 * @property Carbon|null $counted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['console', 'path', 'layout', 'file_count', 'counted_at'])]
class ConsoleSourceFolder extends Model
{
    protected function casts(): array
    {
        return [
            'file_count' => 'integer',
            'counted_at' => 'datetime',
        ];
    }

    /** The container key the rows are held under for one request or one job. */
    private const ROWS = 'console-source-folders.rows';

    /**
     * Every row, keyed by console, read once per request.
     *
     * The table holds one row per console in the library — a couple of dozen —
     * and the consoles page used to ask it about each console separately from
     * half a dozen helpers: two hundred queries a page load to read twenty
     * rows. A scoped binding rather than a static: the container flushes it
     * between queue jobs, so a long-lived worker never holds an old copy, and
     * each test starts with a new application. The writes below drop it.
     *
     * @return Collection<string, self>
     */
    private static function rows(): Collection
    {
        if (! app()->bound(self::ROWS)) {
            app()->scoped(self::ROWS, fn (): Collection => static::query()->get()->keyBy('console'));
        }

        /** @var Collection<string, self> */
        return app(self::ROWS);
    }

    /** Drop the rows read so far, after anything that writes to the table. */
    private static function flushRows(): void
    {
        app()->forgetInstance(self::ROWS);
    }

    /**
     * Any row saved or deleted as a model drops them too, so a plain create()
     * cannot leave the rest of the request reading the table as it was. Mass
     * updates fire no events, which is why setLayout() flushes by hand.
     */
    protected static function booted(): void
    {
        static::saved(fn () => self::flushRows());
        static::deleted(fn () => self::flushRows());
    }

    /**
     * The consoles in the library, in the config's own order.
     *
     * @return Collection<int, Console>
     */
    public static function consoles(): Collection
    {
        $rows = self::rows();

        return Console::all()
            ->filter(fn (Console $console) => $rows->has($console->key))
            ->values();
    }

    public static function has(Console $console): bool
    {
        return self::rows()->has($console->key);
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

        self::flushRows();

        // The path and the layout both decide which files count, so a count
        // taken before this was written is about a different folder.
        FolderCounts::recount($console);

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

        self::flushRows();
    }

    /** The count MeasureLibrary last took for this console, or null before the first. */
    public static function fileCountFor(Console $console): ?int
    {
        return self::rows()->get($console->key)?->file_count;
    }

    /** Store a count MeasureLibrary has just taken. */
    public static function recordCount(Console $console, int $count): void
    {
        static::query()
            ->where('console', $console->key)
            ->update(['file_count' => $count, 'counted_at' => now()]);

        self::flushRows();
    }

    /**
     * The scan root for a console: the override if one is set, else convention.
     *
     * Returns a path relative to the library root, which is what files.path is
     * stored against.
     */
    public static function pathFor(Console $console): ?string
    {
        $override = self::rows()->get($console->key)?->path;

        if (is_string($override) && $override !== '') {
            return trim($override, '/');
        }

        return $console->folder !== '' ? $console->folder : null;
    }

    /**
     * The layout key stored for this console, or its declared default.
     *
     * The cheap lookup: no query of its own, no filesystem and no config walk. A
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
        $stored = self::rows()->get($console->key)?->layout;

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

        self::flushRows();

        // A different layout counts different files.
        FolderCounts::recount($console);
    }
}
