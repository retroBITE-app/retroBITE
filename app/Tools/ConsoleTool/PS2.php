<?php

declare(strict_types=1);

namespace App\Tools\ConsoleTool;

use App\Enums\GameStatus;
use App\Enums\MediaKind;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Support\CoverArt;
use App\Support\Layouts\ConsoleLayout;
use App\Support\Layouts\OplLayout;
use App\Support\LibraryPath;
use App\Support\OplText;
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
 * everything on it: ART/SLES_503.86_COV.jpg, CFG/SLES_503.86.cfg, and the
 * filename prefix that tells OPL which disc it is looking at.
 *
 * That prefix is the reason this class, rather than OplLayout, understands the
 * serial format. The shape is PlayStation 2's and the convention of putting it
 * in a filename is OPL's; neither owns it alone, and a layout that knew about
 * disc serials would be carrying one console's identifier format for all 135.
 */
final class PS2 extends ConsoleTools
{
    /** The shape OPL reads a cover in. Every file on a working drive is this. */
    public readonly CoverArt $cover;

    /** The shape OPL reads words in: one ASCII line, cut to what it will show. */
    public readonly OplText $text;

    /**
     * The config a game's export is being written against.
     *
     * A scratch value rather than a fact about this console: the text of the
     * file about to be overwritten, read once by writeConfig() for its skip
     * check and read again below to carry OPL's own settings over. Cleared
     * between games.
     */
    private string $existing = '';

    /**
     * Values, not constants, so a test can name one and nothing has to reach
     * into a class to change what it reads.
     *
     * @param  string  $serialPrefixes  Sony's own list — SCE* published, SL**
     *                                  licensed. Kept in step with
     *                                  PS2_SERIAL_PREFIXES in Inspect.sh, and
     *                                  deliberately not in config: a wrong
     *                                  prefix silently mis-titles a library.
     * @param  string  $configDir  OPL's own directories, which OplLayout
     *                             scaffolds under the same names.
     */
    public function __construct(
        public readonly string $serialPrefixes = 'SLUS|SLES|SLPS|SLPM|SCUS|SCES|SCPS|SCPM|SCAJ|SLKA|SCKA|SLAJ',
        public readonly string $configDir = 'CFG',
        public readonly string $artDir = 'ART',
        public readonly string $coverSuffix = '_COV',
    ) {
        // Built here rather than promoted: PHP allows no `new` in a parameter
        // default. The sizes live with the encoders that apply them, not in
        // config, because "every file on a working drive is this size" is not
        // something to invite somebody to tune.
        $this->cover = new CoverArt(256, 368);
        $this->text = new OplText;
    }

    /** @return string[] */
    public function exports(): array
    {
        return ['cfg', 'art'];
    }

    /** Only a drive arranged the way OPL expects has a CFG/ or an ART/. */
    public function canExport(): bool
    {
        return $this->arrangedForOpl();
    }

    /**
     * The serial, and what can be had alongside it.
     *
     * @return array{license_id?: string, cover_id?: string, region?: string, video_mode?: string}
     */
    public function inspect(GameFile $file): array
    {
        $this->file = $file;

        return Arr::only($this->runScript(), ['license_id', 'cover_id', 'region', 'video_mode']);
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

        $stripped = trim((string) preg_replace(
            '/^('.$this->serialPrefixes.')[-_][0-9]{3}\.?[0-9]{2}[.\s_-]+/i',
            '',
            $layout->titleFor($relative),
        ));

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
            '/^('.$this->serialPrefixes.')[-_]([0-9]{3})\.?([0-9]{2})/i',
            basename($filename),
            $matches,
        );

        if ($matched !== 1) {
            return null;
        }

        return Str::upper($matches[1]).'_'.$matches[2].'.'.$matches[3];
    }

    /**
     * Write the named export for every identified game on this console.
     *
     * A database read and a file write: the metadata is already here, which is
     * what makes this cheap enough to offer as a button. The previous build's
     * shell script asked ScreenScraper once per disc to learn the same things.
     *
     * @return array{written: int, skipped: int, failed: int}
     */
    public function run(): array
    {
        return in_array($this->export, $this->exports(), true)
            ? $this->runEach()
            : parent::run();
    }

    /**
     * Run the chosen export over every game on the console, counting as it goes.
     *
     * @return array{written: int, skipped: int, failed: int}
     */
    private function runEach(): array
    {
        $counts = ['written' => 0, 'skipped' => 0, 'failed' => 0];

        if (! $this->arrangedForOpl()) {
            return $counts;
        }

        // Counted up front so the caller can say twelve of nineteen rather
        // than twelve of nothing. One extra query per export, not per game.
        $this->total = Game::query()->forConsole($this->console->key)->count();
        $this->done = 0;

        $this->report();

        Game::query()->forConsole($this->console->key)->each(function (Game $game) use (&$counts): void {
            $this->game = $game;
            $this->existing = '';

            try {
                $counts[$this->writeCurrent() ? 'written' : 'skipped']++;
            } catch (Throwable $e) {
                // One unreadable cover or one unwritable file must not stop the
                // other four hundred.
                $counts['failed']++;

                Log::warning('An OPL export failed for one game.', [
                    'game' => $game->id,
                    'console' => $game->console,
                    'export' => $this->export,
                    'reason' => $e->getMessage(),
                ]);
            }

            // Outside the try: a game that failed is still a game gone past,
            // and a bar that stalls on one unwritable cover says the wrong
            // thing about what the worker is doing.
            $this->done++;

            $this->report();
        });

        $this->game = null;
        $this->existing = '';

        return $counts;
    }

    /** Write whichever export the chain named, for the game it is on. */
    private function writeCurrent(): bool
    {
        return match ($this->export) {
            'cfg' => $this->writeConfig(),
            'art' => $this->writeArt(),
            default => false,
        };
    }

    /**
     * Write the current game's config, or decline to.
     *
     * False is a skip — no serial to name the file by, not identified yet, or
     * already written. A failure throws.
     */
    private function writeConfig(): bool
    {
        $serial = $this->serialForCurrentGame();

        if ($serial === null) {
            return false;
        }

        $gate = app(LibraryPath::class);
        $path = $this->configDir.'/'.$serial.'.cfg';
        $this->existing = $gate->get($this->console, $path);

        // Already written. Rerunning is meant to be cheap and is meant not to
        // fight somebody who edited a title by hand.
        if (! $this->force && Str::contains($this->existing, 'Title=')) {
            return false;
        }

        $gate->ensureDirectory($this->console, $this->configDir);
        $gate->put($this->console, $path, $this->configForCurrentGame());

        return true;
    }

    /**
     * Re-encode the current game's cached cover into the shape OPL reads.
     *
     * Nothing is downloaded: a game whose cover was never scraped is skipped
     * rather than fetched, because a provider request hidden behind a file
     * export is a quota spend nobody asked for.
     *
     * The file is written as JPEG, and the skip check therefore only ever asks
     * about the JPEG. A drive written by an older build carries _COV.png for
     * every game; those are left where they are — OPL reads them and deleting
     * somebody's artwork is not this method's business — so the first run
     * after the format changed re-encodes the whole library and leaves two
     * files per game behind. Widening the check to the .png sibling would
     * spare that at the cost of never migrating an old drive at all.
     */
    private function writeArt(): bool
    {
        $serial = $this->serialForCurrentGame();
        $game = $this->game;

        if ($serial === null || $game === null) {
            return false;
        }

        $game->loadMissing('media');
        $artwork = $game->artwork(MediaKind::Cover);

        if ($artwork === null) {
            return false;
        }

        $gate = app(LibraryPath::class);
        $path = $this->artDir.'/'.$serial.$this->coverSuffix.'.'.$this->cover->format->value;

        if (! $this->force && $gate->exists($this->console, $path)) {
            return false;
        }

        $source = Storage::disk('media')->get($artwork->path);

        if (! is_string($source) || $source === '') {
            return false;
        }

        $gate->ensureDirectory($this->console, $this->artDir);
        $gate->put($this->console, $path, $this->cover->encode($source));

        return true;
    }

    /**
     * The serial to name the current game's file after, or null where none.
     *
     * Three refusals in one: a console arranged some other way has no CFG/ or
     * ART/ to write into, a game still carrying its filename as a title would
     * write that filename back out as metadata, and a disc whose serial has not
     * been read has nothing to be named after.
     */
    private function serialForCurrentGame(): ?string
    {
        if ($this->game === null || ! $this->arrangedForOpl()) {
            return null;
        }

        if ($this->game->status !== GameStatus::Matched) {
            return null;
        }

        return $this->game->licenseId();
    }

    /** Whether this console's folder is arranged the way OPL expects. */
    private function arrangedForOpl(): bool
    {
        return ConsoleSourceFolder::layoutKeyFor($this->console) === (new OplLayout)->key();
    }

    /**
     * The whole text of the current game's config file.
     *
     * Any $-prefixed line already in the file is carried over. Those are OPL's
     * own per-game settings — $DMA, $VMC, a compatibility mask somebody worked
     * out by trial — and dropping them would quietly reset a game that ran.
     */
    private function configForCurrentGame(): string
    {
        $game = $this->game;

        if ($game === null) {
            return '';
        }

        $fields = [
            'Title' => $game->title,
            'Genre' => (string) $game->genre,
            // Provider dates arrive as a date or a full timestamp.
            'Release' => Str::before((string) $game->release_date, 'T'),
            'Developer' => (string) $game->developer,
            'Description' => $this->text->summarise((string) $game->description),
        ];

        $lines = [];

        foreach ($fields as $key => $value) {
            $value = $this->text->oneLine($value);

            // An empty field is left out rather than written blank: OPL shows
            // the key either way, and "Developer=" reads as a missing answer.
            if ($value !== '') {
                $lines[] = $key.'='.$value;
            }
        }

        $title = $this->text->oneLine($game->title);

        if ($title !== '') {
            // A comment to OPL. Kept because the files on a working drive have
            // it, and a diff against one should come back empty.
            $lines[] = '#LongName='.$title;
        }

        foreach ($this->userSettings() as $setting) {
            $lines[] = $setting;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * OPL's own settings out of the config file we are about to overwrite.
     *
     * @return string[]
     */
    private function userSettings(): array
    {
        $kept = [];

        foreach (preg_split('/\R/', $this->existing) ?: [] as $line) {
            if (Str::startsWith(trim($line), '$')) {
                $kept[] = rtrim($line);
            }
        }

        return $kept;
    }
}
