<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FileRole;
use App\Enums\GameStatus;
use App\Exceptions\ScanAborted;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Support\Console;
use App\Support\Layouts\ConsoleLayout;
use App\Support\Scanning\CueSheet;
use App\Support\Scanning\Playlist;
use App\Support\Scanning\ScanPlan;
use App\Support\Scanning\ScanResult;
use App\Support\Scanning\ScanUnit;
use App\Tools\ConsoleTools;
use FilesystemIterator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Reads a console's folder and records what is there.
 *
 * Never writes to the library: files are opened to be read and nothing else.
 * Nothing is moved, copied or renamed, which is the whole premise — the files
 * belong to whoever put them there.
 *
 * The work is done in passes, hardest first, because that is what keeps the
 * provider quota intact. A multi-disc PlayStation game is a playlist, four
 * cuesheets and four tracks: nine files that are one game. Walking the folder
 * flat would make nine games out of them and spend nine lookups discovering
 * that eight were wrong — and a failed lookup costs ten times a successful
 * one against ScreenScraper's allowance. Reading the playlist first costs one.
 *
 * The passes make a plan, which writes nothing; recording it is a second
 * step that can be cut into chunks, so a large console is many short jobs
 * whose progress can be watched rather than one that runs for minutes.
 */
final class LibraryScanner
{
    /** Files that name other files, in the order they are resolved. */
    private const PLAYLIST_EXTENSIONS = ['m3u'];

    private const SHEET_EXTENSIONS = ['cue', 'gdi', 'ccd'];

    /** @var array<string, true> relative paths already put in a unit of the plan being made */
    private array $claimed = [];

    /** How this console's folder is arranged, as whoever added it said. */
    private ConsoleLayout $layout;

    /** This console's own reading of its filenames, where it has one. */
    private ?ConsoleTools $tools = null;

    /** The console's root, relative to the library root. */
    private string $relativeRoot = '';

    /**
     * The whole scan in one go: plan, mark what has gone, record everything.
     *
     * What `retrobite:scan` without --queue runs. Queued, the same three steps
     * are ScanConsoleFolder, then a ScanFolderChunk per chunk of the plan.
     */
    public function scan(Console $console): ScanResult
    {
        $plan = $this->plan($console);

        $result = (new ScanResult($console->key))
            ->with('filesSkipped', $plan->skipped)
            ->with('filesMissing', $this->markMissing($console, $plan));

        return $result->plus($this->record($console, $plan->units));
    }

    /**
     * Walk the folder and work out what it holds, writing nothing.
     *
     * The passes run here, over the whole folder, so a chunk recorded later
     * can never mistake a disc a playlist names for a loose game. Each file
     * goes into one unit only: two chunks writing the same path at once would
     * race on its unique index.
     */
    public function plan(Console $console): ScanPlan
    {
        $relativeRoot = ConsoleSourceFolder::pathFor($console);

        if ($relativeRoot === null) {
            throw ScanAborted::unconfigured($console->key);
        }

        $root = $this->libraryRoot().'/'.$relativeRoot;

        if (! is_dir($root)) {
            throw ScanAborted::missingFolder($console->key, $root);
        }

        $this->relativeRoot = $relativeRoot;
        $this->layout = ConsoleSourceFolder::layoutFor($console);
        $this->tools = ConsoleTools::for($console);

        $files = $this->filesUnder($root, $console);

        // An empty folder is a warning sign, not a fact. Marking a console's
        // whole library missing because a disk failed to mount is expensive to
        // undo: every file comes back as new and is identified again.
        if ($files === []) {
            throw ScanAborted::emptyFolder($console->key, $root);
        }

        $this->claimed = [];

        $units = [
            ...$this->planPlaylists($files),
            ...$this->planSheets($files),
        ];

        [$loose, $skipped] = $this->planLooseFiles($console, $files);

        return new ScanPlan([...$units, ...$loose], $skipped);
    }

    /**
     * Write the rows for some units of a plan. One chunk's work.
     *
     * @param  list<ScanUnit>  $units
     */
    public function record(Console $console, array $units): ScanResult
    {
        $result = new ScanResult($console->key);

        foreach ($units as $unit) {
            // A unit already on record stays with the game it belongs to. Its
            // title is the filename's, and an identified game's slug is the
            // provider's, so looking it up by title would make a placeholder,
            // take the files from the game, and send every one of them back
            // through the lookup to be merged home again.
            $game = $this->ownerOf($unit);
            $created = false;

            if ($game === null) {
                [$game, $created] = $this->gameFor($console, $unit->title);
            }

            $result = $created ? $result->with('gamesCreated') : $result;

            /** @var array<string, int> $ids */
            $ids = [];

            foreach ($unit->files as $file) {
                [$row, $result] = $this->recordFile(
                    $game,
                    $file['path'],
                    $file['size'],
                    $file['role'],
                    $result,
                    $file['parent'] === null ? null : ($ids[$file['parent']] ?? null),
                    $file['disc'],
                );

                $ids[$file['path']] = $row->id;
            }
        }

        return $result;
    }

    /**
     * The game a unit's files are already recorded under, if any.
     *
     * The unit's first file decides — the playlist, sheet or loose file the
     * game was named after — then the rest in order.
     */
    private function ownerOf(ScanUnit $unit): ?Game
    {
        $owners = GameFile::query()
            ->whereIn('path', array_column($unit->files, 'path'))
            ->pluck('game_id', 'path');

        foreach ($unit->files as $file) {
            $game = isset($owners[$file['path']]) ? Game::query()->find((int) $owners[$file['path']]) : null;

            if ($game !== null) {
                return $game;
            }
        }

        return null;
    }

    /**
     * Flag rows under this console's root that the plan does not hold.
     *
     * Before the chunks rather than after them: the plan already knows every
     * file there is, and a file that is back is cleared again by its chunk.
     *
     * @return int how many were flagged
     */
    public function markMissing(Console $console, ScanPlan $plan): int
    {
        $relativeRoot = ConsoleSourceFolder::pathFor($console);

        if ($relativeRoot === null) {
            return 0;
        }

        $present = $plan->paths();

        // Compared here rather than in a whereNotIn: a large console is tens
        // of thousands of paths, past what one statement can bind.
        $stale = GameFile::query()
            ->whereNull('missing_since')
            ->where('path', 'like', $relativeRoot.'/%')
            ->pluck('path', 'id')
            ->reject(fn (string $path): bool => isset($present[$path]))
            ->keys();

        foreach ($stale->chunk(1000) as $ids) {
            GameFile::query()->whereIn('id', $ids)->update(['missing_since' => now()]);
        }

        return $stale->count();
    }

    /**
     * Pass one: playlists, and everything they name.
     *
     * @param  array<string, int|null>  $files  sizes, keyed by relative path
     * @return list<ScanUnit>
     */
    private function planPlaylists(array $files): array
    {
        $units = [];

        foreach ($files as $relative => $size) {
            if (! $this->extensionIn($relative, self::PLAYLIST_EXTENSIONS) || isset($this->claimed[$relative])) {
                continue;
            }

            $entries = Playlist::entriesIn((string) file_get_contents($this->absolute($relative)));

            if ($entries === []) {
                continue;
            }

            $planned = [$this->planned($relative, $size, FileRole::Playlist)];

            $disc = 0;
            foreach ($entries as $entry) {
                $discRelative = $this->resolve($relative, $entry, $files);

                if ($discRelative === null) {
                    continue;
                }

                $disc++;

                if (isset($this->claimed[$discRelative])) {
                    continue;
                }

                // An entry may itself be a cuesheet, in which case its tracks
                // hang off it and the disc number belongs to the sheet.
                $role = $this->extensionIn($discRelative, self::SHEET_EXTENSIONS)
                    ? FileRole::Sheet
                    : FileRole::Rom;

                $planned[] = $this->planned($discRelative, $files[$discRelative], $role, $relative, $disc);

                if ($role === FileRole::Sheet) {
                    array_push($planned, ...$this->planTracks($discRelative, $files, $disc));
                }
            }

            $units[] = new ScanUnit($this->titleFrom($relative), $planned);
        }

        return $units;
    }

    /**
     * Pass two: cuesheets nothing has claimed, and their tracks.
     *
     * @param  array<string, int|null>  $files
     * @return list<ScanUnit>
     */
    private function planSheets(array $files): array
    {
        $units = [];

        foreach ($files as $relative => $size) {
            if (! $this->extensionIn($relative, self::SHEET_EXTENSIONS) || isset($this->claimed[$relative])) {
                continue;
            }

            $units[] = new ScanUnit($this->titleFrom($relative), [
                $this->planned($relative, $size, FileRole::Sheet),
                ...$this->planTracks($relative, $files, null),
            ]);
        }

        return $units;
    }

    /**
     * Pass three: whatever is left and carries a playable extension.
     *
     * @param  array<string, int|null>  $files
     * @return array{0: list<ScanUnit>, 1: int} the units, and how many files were not playable
     */
    private function planLooseFiles(Console $console, array $files): array
    {
        $units = [];
        $skipped = 0;

        foreach ($files as $relative => $size) {
            if (isset($this->claimed[$relative])) {
                continue;
            }

            if (! $this->isPlayable($console, $relative)) {
                $skipped++;

                continue;
            }

            $units[] = new ScanUnit($this->titleFrom($relative), [
                $this->planned($relative, $size, FileRole::Rom),
            ]);
        }

        return [$units, $skipped];
    }

    /**
     * The tracks a sheet names, as children of that sheet.
     *
     * @param  array<string, int|null>  $files
     * @return list<array{path: string, size: int|null, role: FileRole, parent: string|null, disc: int|null}>
     */
    private function planTracks(string $sheetRelative, array $files, ?int $disc): array
    {
        $tracks = [];

        foreach (CueSheet::tracksIn((string) file_get_contents($this->absolute($sheetRelative))) as $track) {
            $trackRelative = $this->resolve($sheetRelative, $track, $files);

            if ($trackRelative === null || isset($this->claimed[$trackRelative])) {
                continue;
            }

            $tracks[] = $this->planned($trackRelative, $files[$trackRelative], FileRole::Track, $sheetRelative, $disc);
        }

        return $tracks;
    }

    /**
     * One file of a unit, claimed so no later pass puts it in another.
     *
     * @return array{path: string, size: int|null, role: FileRole, parent: string|null, disc: int|null}
     */
    private function planned(string $relative, ?int $size, FileRole $role, ?string $parent = null, ?int $disc = null): array
    {
        $this->claimed[$relative] = true;

        return ['path' => $relative, 'size' => $size, 'role' => $role, 'parent' => $parent, 'disc' => $disc];
    }

    /**
     * Write one file row, or bring an existing one back.
     *
     * @return array{0: GameFile, 1: ScanResult}
     */
    private function recordFile(
        Game $game,
        string $relative,
        ?int $size,
        FileRole $role,
        ScanResult $result,
        ?int $parentId = null,
        ?int $disc = null,
    ): array {
        $existing = GameFile::query()->where('path', $relative)->first();
        $returning = $existing !== null && $existing->missing_since !== null;

        $file = GameFile::query()->updateOrCreate(['path' => $relative], [
            'game_id' => $game->id,
            'filename' => basename($relative),
            'extension' => $this->extension($relative),
            'size_bytes' => $size,
            'role' => $role,
            'disc_number' => $disc,
            'parent_id' => $parentId,
            // A file that is back is no longer missing. Its checksum and
            // identification survive, which is the point of not deleting it.
            'missing_since' => null,
        ]);

        $result = $result->with('filesSeen');

        if ($existing === null) {
            $result = $result->with('filesCreated');
        } elseif ($returning) {
            $result = $result->with('filesReturned');
        }

        return [$file, $result];
    }

    /**
     * Every file under the root and its size, keyed by its path relative to
     * the library.
     *
     * Sizes and not the SplFileInfo itself: a clone keeps a whole directory
     * entry and costs ten times as much, which a large console's folder spent
     * past the worker's memory before the first row was written.
     *
     * @return array<string, int|null>
     */
    private function filesUnder(string $root, Console $console): array
    {
        $excluded = array_map('strtolower', $console->excludeFiles);
        $prefix = $this->libraryRoot().'/';
        $found = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $info) {
            if (! $info instanceof SplFileInfo || ! $info->isFile()) {
                continue;
            }

            if (in_array(strtolower($info->getFilename()), $excluded, true)) {
                continue;
            }

            $relative = str_replace($prefix, '', $info->getPathname());

            // The layout decides what is even a candidate. On an OPL drive
            // that drops ART/, CFG/ and VMC/ before any of them can be read
            // as a game; on a custom one it drops nothing.
            if (! $this->layout->accepts($this->consoleRelative($relative))) {
                continue;
            }

            $size = $info->getSize();
            $found[$relative] = $size === false ? null : $size;
        }

        ksort($found);

        return $found;
    }

    /**
     * Resolve a name from inside a sheet or playlist to a path in this scan.
     *
     * Only ever returns something already found under the root. A playlist
     * naming ../../etc/passwd resolves to nothing, because nothing outside the
     * console's folder was ever collected.
     *
     * @param  array<string, int|null>  $files
     */
    private function resolve(string $fromRelative, string $entry, array $files): ?string
    {
        $entry = trim(str_replace('\\', '/', $entry));

        if ($entry === '') {
            return null;
        }

        $directory = dirname($fromRelative);
        $candidate = $directory === '.' ? $entry : $directory.'/'.$entry;

        // Collapse . and .. without touching the disk.
        $parts = [];
        foreach (explode('/', $candidate) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        $resolved = implode('/', $parts);

        // Exactly as written, or not at all. A sheet whose names no longer
        // match its files is its owner's to fix, not something to guess at.
        return isset($files[$resolved]) ? $resolved : null;
    }

    /**
     * Whether a loose file is something this console plays.
     *
     * Console::playsExtension() is the one definition, shared with the count
     * the console cards show, so the card and the scan cannot disagree about
     * what is in a folder.
     */
    private function isPlayable(Console $console, string $relative): bool
    {
        $extension = $this->extension($relative);

        return $extension !== '' && $console->playsExtension($extension);
    }

    /**
     * Find or make the game a file belongs to.
     *
     * @return array{0: Game, 1: bool} the game, and whether it was created
     */
    private function gameFor(Console $console, string $title, int $attempt = 1): array
    {
        $slug = $this->availableSlug($console, $title);

        $existing = Game::query()
            ->where('console', $console->key)
            ->where('slug', $slug)
            ->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            return [Game::query()->create([
                'console' => $console->key,
                'title' => $title,
                'slug' => $slug,
                'status' => GameStatus::Placeholder,
            ]), true];
        } catch (UniqueConstraintViolationException $e) {
            // Another chunk took the slug between the look and the write. The
            // plan keeps one slug to one chunk, so this is the rare suffixed
            // slug another title happens to reduce to. Looked for again, it is
            // either this title's game now or taken, and the next one is free.
            if ($attempt >= 3) {
                throw $e;
            }

            return $this->gameFor($console, $title, $attempt + 1);
        }
    }

    /**
     * A slug free on this console, or the one already pointing at this title.
     *
     * "Game (USA).iso" and "Game (Europe).iso" both reduce to the same slug,
     * and the unique index would reject the second. They are usually the same
     * game and the matcher will merge them, but that happens later — the scan
     * has to be able to write both rows first.
     */
    private function availableSlug(Console $console, string $title): string
    {
        $base = Str::slug($title) ?: 'game';
        $slug = $base;
        $suffix = 1;

        while (true) {
            $game = Game::query()
                ->where('console', $console->key)
                ->where('slug', $slug)
                ->first();

            if ($game === null || $game->title === $title) {
                return $slug;
            }

            $suffix++;
            $slug = $base.'-'.$suffix;
        }
    }

    /**
     * A placeholder title, before the provider has been asked.
     *
     * The console is asked first and the layout second. Where a filename
     * convention belongs to the two together — a PS2 serial prefix, which is
     * OPL's idea and means nothing on a RetroArch drive — neither can answer
     * alone, so the console's toolbox gets the first word and the layout's own
     * reading is the fallback.
     */
    private function titleFrom(string $relative): string
    {
        $consoleRelative = $this->consoleRelative($relative);

        return $this->tools?->titleFor($this->layout, $consoleRelative)
            ?? $this->layout->titleFor($consoleRelative);
    }

    /**
     * A library-relative path as the console's own folder sees it.
     *
     * Layouts think in console-relative terms — DVD/Game.iso, not
     * games/ps2/DVD/Game.iso — because where a console's root sits is
     * ConsoleSourceFolder's business and none of theirs.
     */
    private function consoleRelative(string $relative): string
    {
        return Str::after($relative, $this->relativeRoot.'/');
    }

    private function extension(string $relative): string
    {
        return strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    }

    /** @param  array<int, string>  $extensions */
    private function extensionIn(string $relative, array $extensions): bool
    {
        return in_array($this->extension($relative), $extensions, true);
    }

    private function absolute(string $relative): string
    {
        return $this->libraryRoot().'/'.$relative;
    }

    private function libraryRoot(): string
    {
        return rtrim((string) config('settings.games_path'), '/');
    }
}
