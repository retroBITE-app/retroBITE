<?php

declare(strict_types=1);

namespace App\Tools\ConsoleTool;

use App\Enums\GameStatus;
use App\Enums\MediaKind;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Support\Console;
use App\Support\CoverArt;
use App\Support\Layouts\ConsoleLayout;
use App\Support\Layouts\OplLayout;
use App\Support\LibraryPath;
use App\Tools\ConsoleTools;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * What a PlayStation 2 disc says about itself.
 *
 * Every PS2 disc carries a serial — SLES_503.86 and its kin — declared by BOOT2
 * in SYSTEM.CNF. ScreenScraper does not return it, and Open PS2 Loader keys
 * everything on it: ART/SLES_503.86_COV.png, CFG/SLES_503.86.cfg, and the
 * filename prefix that tells OPL which disc it is looking at.
 *
 * That prefix is the reason this class, rather than OplLayout, understands the
 * serial format. The shape is PlayStation 2's and the convention of putting it
 * in a filename is OPL's; neither owns it alone, and a layout that knew about
 * disc serials would be carrying one console's identifier format for all 135.
 */
final class PS2 extends ConsoleTools
{
    /**
     * Sony's PS2 serial prefixes. SCE* are Sony-published, SL** licensed.
     *
     * Kept in step with PS2_SERIAL_PREFIXES in Inspect.sh, which is the same
     * list for the same reason.
     */
    private const SERIAL_PREFIXES = 'SLUS|SLES|SLPS|SLPM|SCUS|SCES|SCPS|SCPM|SCAJ|SLKA|SCKA|SLAJ';

    /** Where OPL keeps a game's metadata, and what it calls its artwork. */
    private const CONFIG_DIR = 'CFG';

    private const ART_DIR = 'ART';

    private const COVER_SUFFIX = '_COV';

    /**
     * How much of a synopsis OPL will show.
     *
     * Kept at the previous build's figure so regenerating a drive does not
     * rewrite every file it already has for the sake of a different cut.
     */
    private const DESCRIPTION_MAX = 300;

    /** What OPL reads a cover at. Every file on a working drive is this size. */
    private const COVER_WIDTH = 256;

    private const COVER_HEIGHT = 368;

    public function consoleKey(): string
    {
        return 'ps2';
    }

    /** @return string[] */
    public function exports(): array
    {
        return ['cfg', 'art'];
    }

    /**
     * The serial, and what can be had alongside it.
     *
     * @return array{license_id?: string, cover_id?: string, region?: string, video_mode?: string}
     */
    public function inspect(GameFile $file): array
    {
        $facts = $this->run(self::SCRIPT, [$this->absolutePath($file)]);

        return Arr::only($facts, ['license_id', 'cover_id', 'region', 'video_mode']);
    }

    /**
     * The title with its OPL serial prefix removed, on an OPL drive only.
     *
     * "SLES_503.86.Tekken Tag Tournament.iso" is Tekken Tag Tournament where
     * OPL put it, and is a file named exactly that anywhere else. Null under
     * every other layout, so the layout's own reading stands.
     */
    public function titleFor(ConsoleLayout $layout, string $relative): ?string
    {
        if (! $layout instanceof OplLayout) {
            return null;
        }

        $title = $layout->titleFor($relative);
        $stripped = preg_replace(
            '/^('.self::SERIAL_PREFIXES.')[-_][0-9]{3}\.?[0-9]{2}[.\s_-]+/i',
            '',
            $title,
        );

        $stripped = trim((string) $stripped);

        // A disc named after nothing but its serial keeps that name: an empty
        // title would slug to "game" and lose the only thing it said.
        return $stripped !== '' ? $stripped : null;
    }

    /**
     * The serial a filename already carries, in either spelling.
     *
     * Free next to opening a four-gigabyte image, which is why inspect() is not
     * the only caller: the renamer uses it to tell a file that already has its
     * prefix from one that needs it.
     */
    public function serialFrom(string $filename): ?string
    {
        $matched = preg_match(
            '/^('.self::SERIAL_PREFIXES.')[-_]([0-9]{3})\.?([0-9]{2})/i',
            basename($filename),
            $matches,
        );

        if ($matched !== 1) {
            return null;
        }

        return Str::upper($matches[1]).'_'.$matches[2].'.'.$matches[3];
    }

    /**
     * @param  null|callable(int, int): void  $onProgress  called with (done, total) as it goes
     * @return array{written: int, skipped: int, failed: int}
     */
    public function export(Console $console, string $export, bool $force = false, ?callable $onProgress = null): array
    {
        return match ($export) {
            'cfg' => $this->writeGameConfigs($console, $force, $onProgress),
            'art' => $this->writeGameArt($console, $force, $onProgress),
            default => ['written' => 0, 'skipped' => 0, 'failed' => 0],
        };
    }

    /**
     * Write OPL's per-game config for every identified game on this console.
     *
     * A database read and a file write: the metadata is already here, which is
     * what makes this cheap enough to offer as a button. The previous build's
     * shell script asked ScreenScraper once per disc to learn the same things.
     *
     * @return array{written: int, skipped: int, failed: int}
     */
    public function writeGameConfigs(Console $console, bool $force = false, ?callable $onProgress = null): array
    {
        return $this->exportEach($console, function (Game $game) use ($force): bool {
            return $this->writeGameConfig($game, $force);
        }, $onProgress);
    }

    /**
     * Write one game's config, or decline to.
     *
     * False is a skip — no serial to name the file by, not identified yet, or
     * already written. A failure throws.
     */
    public function writeGameConfig(Game $game, bool $force = false): bool
    {
        $console = $game->console();
        $serial = $this->exportTargetFor($game, $console);

        if ($console === null || $serial === null) {
            return false;
        }

        $gate = app(LibraryPath::class);
        $path = self::CONFIG_DIR.'/'.$serial.'.cfg';
        $existing = $gate->get($console, $path);

        // Already written. Rerunning is meant to be cheap and is meant not to
        // fight somebody who edited a title by hand.
        if (! $force && Str::contains($existing, 'Title=')) {
            return false;
        }

        $gate->ensureDirectory($console, self::CONFIG_DIR);
        $gate->put($console, $path, $this->configFor($game, $existing));

        return true;
    }

    /**
     * Re-encode each game's cached cover into the shape OPL reads.
     *
     * Nothing is downloaded: a game whose cover was never scraped is skipped
     * rather than fetched, because a provider request hidden behind a file
     * export is a quota spend nobody asked for.
     *
     * @return array{written: int, skipped: int, failed: int}
     */
    public function writeGameArt(Console $console, bool $force = false, ?callable $onProgress = null): array
    {
        return $this->exportEach($console, function (Game $game) use ($force): bool {
            return $this->writeGameArtFor($game, $force);
        }, $onProgress);
    }

    /** Write one game's cover, or decline to. */
    public function writeGameArtFor(Game $game, bool $force = false): bool
    {
        $console = $game->console();
        $serial = $this->exportTargetFor($game, $console);

        if ($console === null || $serial === null) {
            return false;
        }

        $game->loadMissing('media');
        $cover = $game->artwork(MediaKind::Cover);

        if ($cover === null) {
            return false;
        }

        $gate = app(LibraryPath::class);
        $path = self::ART_DIR.'/'.$serial.self::COVER_SUFFIX.'.png';

        if (! $force && $gate->exists($console, $path)) {
            return false;
        }

        $source = Storage::disk('media')->get($cover->path);

        if (! is_string($source) || $source === '') {
            return false;
        }

        $gate->ensureDirectory($console, self::ART_DIR);
        $gate->put($console, $path, (new CoverArt(self::COVER_WIDTH, self::COVER_HEIGHT))->encode($source));

        return true;
    }

    /**
     * Run one export over every game on a console, counting as it goes.
     *
     * @param  callable(Game): bool  $export
     * @param  null|callable(int, int): void  $onProgress  called with (done, total) after each game
     * @return array{written: int, skipped: int, failed: int}
     */
    private function exportEach(Console $console, callable $export, ?callable $onProgress = null): array
    {
        $counts = ['written' => 0, 'skipped' => 0, 'failed' => 0];

        if (! $this->arrangedForOpl($console)) {
            return $counts;
        }

        // Counted up front so the caller can say twelve of nineteen rather
        // than twelve of nothing. One extra query per export, not per game.
        $total = Game::query()->forConsole($console->key)->count();
        $done = 0;

        if ($onProgress !== null) {
            $onProgress(0, $total);
        }

        Game::query()->forConsole($console->key)->each(function (Game $game) use ($export, $onProgress, $total, &$counts, &$done): void {
            try {
                $counts[$export($game) ? 'written' : 'skipped']++;
            } catch (Throwable $e) {
                // One unreadable cover or one unwritable file must not stop the
                // other four hundred.
                $counts['failed']++;

                Log::warning('An OPL export failed for one game.', [
                    'game' => $game->id,
                    'console' => $game->console,
                    'reason' => $e->getMessage(),
                ]);
            }

            // Outside the try: a game that failed is still a game gone past,
            // and a bar that stalls on one unwritable cover says the wrong
            // thing about what the worker is doing.
            $done++;

            if ($onProgress !== null) {
                $onProgress($done, $total);
            }
        });

        return $counts;
    }

    /**
     * The serial to name an exported file after, or null where there is none.
     *
     * Three refusals in one: a console arranged some other way has no CFG/ or
     * ART/ to write into, a game still carrying its filename as a title would
     * write that filename back out as metadata, and a disc whose serial has not
     * been read has nothing to be named after.
     */
    private function exportTargetFor(Game $game, ?Console $console): ?string
    {
        if ($console === null || ! $this->arrangedForOpl($console)) {
            return null;
        }

        if ($game->status !== GameStatus::Matched) {
            return null;
        }

        return $game->licenseId();
    }

    /** Whether this console's folder is arranged the way OPL expects. */
    private function arrangedForOpl(Console $console): bool
    {
        return ConsoleSourceFolder::layoutKeyFor($console) === (new OplLayout)->key();
    }

    /**
     * The whole text of one game's config file.
     *
     * Any $-prefixed line already in the file is carried over. Those are OPL's
     * own per-game settings — $DMA, $VMC, a compatibility mask somebody worked
     * out by trial — and dropping them would quietly reset a game that ran.
     */
    private function configFor(Game $game, string $existing): string
    {
        $fields = [
            'Title' => $game->title,
            'Genre' => (string) $game->genre,
            // Provider dates arrive as a date or a full timestamp.
            'Release' => Str::before((string) $game->release_date, 'T'),
            'Developer' => (string) $game->developer,
            'Description' => $this->summarise((string) $game->description),
            'Rating' => $game->rating !== null ? (string) $game->rating : '',
        ];

        $lines = [];

        foreach ($fields as $key => $value) {
            $value = $this->oneLine($value);

            // An empty field is left out rather than written blank: OPL shows
            // the key either way, and "Developer=" reads as a missing answer.
            if ($value !== '') {
                $lines[] = $key.'='.$value;
            }
        }

        $title = $this->oneLine($game->title);

        if ($title !== '') {
            // A comment to OPL. Kept because the files on a working drive have
            // it, and a diff against one should come back empty.
            $lines[] = '#LongName='.$title;
        }

        foreach ($this->userSettingsIn($existing) as $setting) {
            $lines[] = $setting;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * OPL's own settings out of a config file we are about to overwrite.
     *
     * @return string[]
     */
    private function userSettingsIn(string $existing): array
    {
        $kept = [];

        foreach (preg_split('/\R/', $existing) ?: [] as $line) {
            if (Str::startsWith(trim($line), '$')) {
                $kept[] = rtrim($line);
            }
        }

        return $kept;
    }

    /**
     * A synopsis cut to what OPL will show, on a word boundary.
     *
     * The ellipsis is three ASCII dots rather than one character, which is what
     * the files on a working drive carry — OPL's font has no glyph for the
     * other one.
     */
    private function summarise(string $text): string
    {
        $text = $this->oneLine($text);

        if (mb_strlen($text) <= self::DESCRIPTION_MAX) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::DESCRIPTION_MAX);
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false && $lastSpace > 0) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t.,;:!?-").'...';
    }

    /**
     * One line of plain ASCII, because that is all OPL can draw.
     */
    private function oneLine(string $value): string
    {
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim(Str::ascii($value));
    }
}
