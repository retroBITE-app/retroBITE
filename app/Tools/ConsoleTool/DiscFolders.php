<?php

declare(strict_types=1);

namespace App\Tools\ConsoleTool;

use App\Enums\FileRole;
use App\Jobs\ScanConsoleFolder;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Support\Layouts\FoldersLayout;
use App\Support\LibraryPath;
use App\Tools\ConsoleTools;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The toolbox of every CD system that keeps one folder per game.
 *
 * Not one console's: PS1, Saturn, Mega CD and the rest share the arrangement
 * and the one thing it needs written, a playlist for a game of more than one
 * disc. So one class is mapped to all of them rather than a copy each. There
 * is nothing on these discs worth reading, so inspect() finds nothing — none
 * of them lists a file extension for it, and it is never asked.
 */
final class DiscFolders extends ConsoleTools
{
    /** The sheets a disc is described by, preferred over the image itself. */
    private const SHEETS = ['cue', 'ccd', 'gdi'];

    /** Single-file disc images, for a folder that has no sheets. */
    private const IMAGES = ['chd', 'iso', 'pbp'];

    public function inspect(GameFile $file): array
    {
        return [];
    }

    /** @return string[] */
    public function exports(): array
    {
        return ['m3u'];
    }

    /** Only where each game has a folder of its own to put a playlist in. */
    public function canExport(): bool
    {
        return ConsoleSourceFolder::layoutFor($this->console) instanceof FoldersLayout;
    }

    /** Loose games can be filed into folders only on the layout that wants them. */
    public function canOrganize(): bool
    {
        return $this->canExport();
    }

    public function exportLabel(string $export): string
    {
        return __('Write playlists');
    }

    public function exportConfirm(string $export, string $folder): string
    {
        return __('Write a playlist into each multi-disc game\'s folder in :folder? Existing playlists are kept, and nothing else is touched.', [
            'folder' => $folder,
        ]);
    }

    /**
     * A playlist for every game of two or more discs that has none yet, then
     * a scan so the playlist links the discs into one set.
     *
     * @return array{written: int, skipped: int, failed: int}
     */
    public function run(): array
    {
        $counts = ['written' => 0, 'skipped' => 0, 'failed' => 0];

        if ($this->export !== 'm3u' || ! $this->canExport()) {
            return $counts;
        }

        $root = (string) ConsoleSourceFolder::pathFor($this->console);

        $this->total = Game::query()->forConsole($this->console->key)->count();
        $this->done = 0;
        $this->report();

        Game::query()->forConsole($this->console->key)->with('files')->each(function (Game $game) use (&$counts, $root): void {
            try {
                $counts[$this->writePlaylist($game, $root) ? 'written' : 'skipped']++;
            } catch (Throwable $e) {
                // One unwritable folder must not stop the others.
                $counts['failed']++;

                Log::warning('A playlist could not be written.', [
                    'game' => $game->id,
                    'console' => $game->console,
                    'reason' => $e->getMessage(),
                ]);
            }

            $this->done++;
            $this->report();
        });

        if ($counts['written'] > 0) {
            ScanConsoleFolder::dispatch($this->console->key);
        }

        return $counts;
    }

    /**
     * Write the game's playlist, or decline to: false when it is one disc,
     * has a playlist already, or is not in a folder of its own.
     */
    private function writePlaylist(Game $game, string $root): bool
    {
        $files = $game->files->filter(function (GameFile $file): bool {
            return $file->isPresent();
        });

        if ($files->isEmpty() || $files->contains(function (GameFile $file): bool {
            return $file->role === FileRole::Playlist;
        })) {
            return false;
        }

        $folders = $files->map(function (GameFile $file) use ($root): string {
            return dirname(Str::after($file->path, $root.'/'));
        })->unique();

        // One folder, and not the console's own: a playlist belongs to a game.
        $folder = $folders->count() === 1 ? (string) $folders->first() : '.';

        if ($folder === '.' || Str::contains($folder, '/')) {
            return false;
        }

        $discs = $this->discsIn($files);

        if ($discs->count() < 2) {
            return false;
        }

        $gate = app(LibraryPath::class);
        $path = $folder.'/'.$folder.'.m3u';

        if ($gate->exists($this->console, $path)) {
            return false;
        }

        $gate->put($this->console, $path, $discs->implode("\n")."\n");

        return true;
    }

    /**
     * The files a playlist lists, one per disc, in disc order.
     *
     * The sheets where there are any — a cue already names its bins — and the
     * single-file images otherwise. Ordered by the "(Disc N)" in the name
     * where there is one, and naturally by name where there is not.
     *
     * @param  Collection<int, GameFile>  $files
     * @return Collection<int, string> file names
     */
    private function discsIn(Collection $files): Collection
    {
        $ofExtension = function (array $extensions) use ($files): Collection {
            return $files->filter(function (GameFile $file) use ($extensions): bool {
                return in_array(strtolower((string) $file->extension), $extensions, true);
            });
        };

        $discs = $ofExtension(self::SHEETS);

        if ($discs->isEmpty()) {
            $discs = $ofExtension(self::IMAGES);
        }

        return $discs
            ->map(function (GameFile $file): string {
                return $file->filename;
            })
            ->sort(function (string $a, string $b): int {
                return ($this->discNumber($a) <=> $this->discNumber($b)) ?: strnatcasecmp($a, $b);
            })
            ->values();
    }

    /** The disc a name says it is, "(Disc 2)" or "Disc 2", or none. */
    private function discNumber(string $name): int
    {
        return Str::isMatch('/\bdisc\s*(\d+)/i', $name)
            ? (int) Str::match('/\bdisc\s*(\d+)/i', $name)
            : PHP_INT_MAX;
    }
}
