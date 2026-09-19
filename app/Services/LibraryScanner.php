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
use App\Support\Scanning\ScanResult;
use App\Tools\ConsoleTools;
use FilesystemIterator;
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
 */
final class LibraryScanner
{
    /** Files that name other files, in the order they are resolved. */
    private const PLAYLIST_EXTENSIONS = ['m3u'];

    private const SHEET_EXTENSIONS = ['cue', 'gdi', 'ccd'];

    /** @var array<string, true> relative paths already claimed by an earlier pass */
    private array $claimed = [];

    /** @var array<string, true> relative paths seen anywhere in this scan */
    private array $seen = [];

    /** How this console's folder is arranged, as whoever added it said. */
    private ConsoleLayout $layout;

    /** This console's own reading of its filenames, where it has one. */
    private ?ConsoleTools $tools = null;

    /** The console's root, relative to the library root. */
    private string $relativeRoot = '';

    public function scan(Console $console): ScanResult
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
        $this->seen = [];

        $result = new ScanResult($console->key);
        $result = $this->scanPlaylists($console, $files, $result);
        $result = $this->scanSheets($console, $files, $result);
        $result = $this->scanLooseFiles($console, $files, $result);

        return $this->markMissing($console, $relativeRoot, $result);
    }

    /**
     * Pass one: playlists, and everything they name.
     *
     * @param  array<string, SplFileInfo>  $files  keyed by relative path
     */
    private function scanPlaylists(Console $console, array $files, ScanResult $result): ScanResult
    {
        foreach ($files as $relative => $info) {
            if (! $this->extensionIn($relative, self::PLAYLIST_EXTENSIONS) || isset($this->claimed[$relative])) {
                continue;
            }

            $entries = Playlist::entriesIn((string) file_get_contents($info->getPathname()));

            if ($entries === []) {
                continue;
            }

            [$game, $created] = $this->gameFor($console, $this->titleFrom($relative));
            $result = $created ? $result->with('gamesCreated') : $result;

            [$playlist, $result] = $this->recordFile($game, $relative, $info, FileRole::Playlist, $result);

            $disc = 0;
            foreach ($entries as $entry) {
                $discRelative = $this->resolve($relative, $entry, $files);

                if ($discRelative === null) {
                    continue;
                }

                $disc++;

                // An entry may itself be a cuesheet, in which case its tracks
                // hang off it and the disc number belongs to the sheet.
                $role = $this->extensionIn($discRelative, self::SHEET_EXTENSIONS)
                    ? FileRole::Sheet
                    : FileRole::Rom;

                [$discFile, $result] = $this->recordFile(
                    $game, $discRelative, $files[$discRelative], $role, $result, $playlist->id, $disc,
                );

                if ($role === FileRole::Sheet) {
                    $result = $this->recordTracks($game, $discRelative, $files, $discFile->id, $disc, $result);
                }
            }
        }

        return $result;
    }

    /**
     * Pass two: cuesheets nothing has claimed, and their tracks.
     *
     * @param  array<string, SplFileInfo>  $files
     */
    private function scanSheets(Console $console, array $files, ScanResult $result): ScanResult
    {
        foreach ($files as $relative => $info) {
            if (! $this->extensionIn($relative, self::SHEET_EXTENSIONS) || isset($this->claimed[$relative])) {
                continue;
            }

            [$game, $created] = $this->gameFor($console, $this->titleFrom($relative));
            $result = $created ? $result->with('gamesCreated') : $result;

            [$sheet, $result] = $this->recordFile($game, $relative, $info, FileRole::Sheet, $result);

            $result = $this->recordTracks($game, $relative, $files, $sheet->id, null, $result);
        }

        return $result;
    }

    /**
     * Pass three: whatever is left and carries a playable extension.
     *
     * @param  array<string, SplFileInfo>  $files
     */
    private function scanLooseFiles(Console $console, array $files, ScanResult $result): ScanResult
    {
        foreach ($files as $relative => $info) {
            if (isset($this->claimed[$relative])) {
                continue;
            }

            if (! $this->isPlayable($console, $relative)) {
                $result = $result->with('filesSkipped');

                continue;
            }

            [$game, $created] = $this->gameFor($console, $this->titleFrom($relative));
            $result = $created ? $result->with('gamesCreated') : $result;

            [, $result] = $this->recordFile($game, $relative, $info, FileRole::Rom, $result);
        }

        return $result;
    }

    /**
     * Record the tracks a sheet names, as children of that sheet.
     *
     * @param  array<string, SplFileInfo>  $files
     */
    private function recordTracks(
        Game $game,
        string $sheetRelative,
        array $files,
        int $parentId,
        ?int $disc,
        ScanResult $result,
    ): ScanResult {
        $sheet = $files[$sheetRelative] ?? null;

        if ($sheet === null) {
            return $result;
        }

        foreach (CueSheet::tracksIn((string) file_get_contents($sheet->getPathname())) as $track) {
            $trackRelative = $this->resolve($sheetRelative, $track, $files);

            if ($trackRelative === null) {
                continue;
            }

            [, $result] = $this->recordFile(
                $game, $trackRelative, $files[$trackRelative], FileRole::Track, $result, $parentId, $disc,
            );
        }

        return $result;
    }

    /**
     * Write one file row, or bring an existing one back.
     *
     * @return array{0: GameFile, 1: ScanResult}
     */
    private function recordFile(
        Game $game,
        string $relative,
        SplFileInfo $info,
        FileRole $role,
        ScanResult $result,
        ?int $parentId = null,
        ?int $disc = null,
    ): array {
        $this->claimed[$relative] = true;
        $this->seen[$relative] = true;

        $existing = GameFile::query()->where('path', $relative)->first();
        $returning = $existing !== null && $existing->missing_since !== null;

        $file = GameFile::query()->updateOrCreate(['path' => $relative], [
            'game_id' => $game->id,
            'filename' => $info->getFilename(),
            'extension' => $this->extension($relative),
            'size_bytes' => $info->getSize() === false ? null : $info->getSize(),
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
     * Flag rows under this console's root that the scan did not find.
     */
    private function markMissing(Console $console, string $relativeRoot, ScanResult $result): ScanResult
    {
        $stale = GameFile::query()
            ->whereNull('missing_since')
            ->where('path', 'like', $relativeRoot.'/%')
            ->whereNotIn('path', array_keys($this->seen))
            ->get();

        foreach ($stale as $file) {
            $file->update(['missing_since' => now()]);
            $result = $result->with('filesMissing');
        }

        return $result;
    }

    /**
     * Every file under the root, keyed by its path relative to the library.
     *
     * @return array<string, SplFileInfo>
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

            $found[$relative] = clone $info;
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
     * @param  array<string, SplFileInfo>  $files
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

        if (isset($files[$resolved])) {
            return $resolved;
        }

        // Sets renamed after the sheet was written are common enough to be
        // worth one fallback: match on filename within the same directory.
        $wanted = strtolower(basename($resolved));
        foreach ($files as $relative => $_) {
            if (dirname($relative) === $directory && strtolower(basename($relative)) === $wanted) {
                return $relative;
            }
        }

        return null;
    }

    /**
     * Whether a loose file is something this console plays.
     *
     * file_extensions wins over bios_extensions where a console lists the same
     * one in both — PS2 has .bin games and a .bin BIOS, and a game the user
     * owns matters more than a firmware image the library does not track.
     */
    private function isPlayable(Console $console, string $relative): bool
    {
        $extension = $this->extension($relative);

        if ($extension === '') {
            return false;
        }

        return in_array($extension, array_map('strtolower', $console->fileExtensions), true);
    }

    /**
     * Find or make the game a file belongs to.
     *
     * @return array{0: Game, 1: bool} the game, and whether it was created
     */
    private function gameFor(Console $console, string $title): array
    {
        $slug = $this->availableSlug($console, $title);

        $existing = Game::query()
            ->where('console', $console->key)
            ->where('slug', $slug)
            ->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        return [Game::query()->create([
            'console' => $console->key,
            'title' => $title,
            'slug' => $slug,
            'status' => GameStatus::Placeholder,
        ]), true];
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

    private function libraryRoot(): string
    {
        return rtrim((string) config('settings.games_path'), '/');
    }
}
