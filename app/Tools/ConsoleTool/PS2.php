<?php

declare(strict_types=1);

namespace App\Tools\ConsoleTool;

use App\Enums\FileRole;
use App\Enums\GameStatus;
use App\Enums\ImageFormat;
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
 * everything on it: ART/SLES_503.86_COV.png and _ICO.png, CFG/SLES_503.86.cfg,
 * and the filename prefix that tells OPL which disc it is looking at.
 *
 * That prefix is the reason this class, rather than OplLayout, understands the
 * serial format. The shape is PlayStation 2's and the convention of putting it
 * in a filename is OPL's; neither owns it alone, and a layout that knew about
 * disc serials would be carrying one console's identifier format for all 135.
 */
final class PS2 extends ConsoleTools
{
    /**
     * The shape OPL reads a cover in: 256x368 and opaque. PNG, because the
     * loader has no JPEG decoder — a _COV.jpg is never drawn.
     */
    public readonly CoverArt $cover;

    /**
     * The shape OPL's info page draws both screenshots in, _SCR and _SCR2:
     * 173x148 in both default themes, cropped to fit rather than stretched.
     */
    public readonly CoverArt $screen;

    /**
     * The shape OPL reads a disc icon in: 128 square with its corners clear,
     * which is exactly its own built-in "disc" image. The default themes draw
     * it beside the cover as ItemIcon, the ART/<serial>_ICO slot.
     */
    public readonly CoverArt $disc;

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
        public readonly string $discSuffix = '_ICO',
        public readonly string $screenshotSuffix = '_SCR',
        public readonly string $titleScreenSuffix = '_SCR2',
    ) {
        // Built here rather than promoted: PHP allows no `new` in a parameter
        // default. The sizes live with the encoders that apply them, not in
        // config, because "every file on a working drive is this size" is not
        // something to invite somebody to tune.
        $this->cover = new CoverArt(256, 368, ImageFormat::Png);
        $this->screen = new CoverArt(173, 148, ImageFormat::Png);
        $this->disc = new CoverArt(128, 128, ImageFormat::Png, transparent: true);
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

        $stripped = $this->withoutLicenseId($layout->titleFor($relative));

        // A disc named after nothing but its serial keeps that name: an empty
        // title would slug to "game" and lose the only thing it said.
        return $stripped !== '' ? $stripped : null;
    }

    /** Renaming is to or from OPL's own filename convention, so only on its drive. */
    public function canRename(): bool
    {
        return $this->arrangedForOpl();
    }

    /**
     * The file's name with its license ID put in front, OPL's old
     * "SLES_503.86.Title.iso" form, or taken off again; null when there is
     * nothing to do.
     *
     * Single-file games only: a cue names its tracks and a playlist its discs,
     * so renaming one file of a set would break the sheet that points at it.
     * The rest of the name is the file's own either way, so adding and then
     * removing the license ID comes back to exactly the name it started with.
     */
    public function renamedFilename(GameFile $file, bool $withLicenseId): ?string
    {
        // Loaded by a caller asking about a whole shelf, which would otherwise
        // be a query per file.
        $hasChildren = $file->relationLoaded('children')
            ? $file->children->isNotEmpty()
            : $file->children()->exists();

        if ($file->role !== FileRole::Rom || $file->parent_id !== null || $hasChildren) {
            return null;
        }

        $carries = $this->serialFrom($file->filename) !== null;

        if ($withLicenseId) {
            // Already there, or not read off the disc yet — the name cannot
            // be made up from anything else.
            return $carries || $file->license_id === null ? null : $file->license_id.'.'.$file->filename;
        }

        if (! $carries) {
            return null;
        }

        $extension = pathinfo($file->filename, PATHINFO_EXTENSION);
        $stem = pathinfo($file->filename, PATHINFO_FILENAME);
        $stripped = $this->withoutLicenseId($stem);

        // A file named for its license ID and nothing else has no other name
        // to go back to — the prefix pattern wants a separator after the ID,
        // so it leaves such a name exactly as it was.
        if ($stripped === '' || $stripped === $stem) {
            return null;
        }

        return $stripped.($extension !== '' ? '.'.$extension : '');
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
     * Re-encode the current game's cached artwork into the files OPL reads:
     * the cover (_COV), the disc icon (_ICO) and the info page's in-game and
     * title screenshots (_SCR, _SCR2), whichever of them it has.
     *
     * Nothing is downloaded: a game whose artwork was never scraped is skipped
     * rather than fetched, because a provider request hidden behind a file
     * export is a quota spend nobody asked for. True when either was written.
     */
    private function writeArt(): bool
    {
        $serial = $this->serialForCurrentGame();
        $game = $this->game;

        if ($serial === null || $game === null) {
            return false;
        }

        $game->loadMissing('media');

        $pieces = [
            [MediaKind::Cover, $this->coverSuffix, $this->cover],
            [MediaKind::Disc, $this->discSuffix, $this->disc],
            [MediaKind::Screenshot, $this->screenshotSuffix, $this->screen],
            [MediaKind::TitleScreen, $this->titleScreenSuffix, $this->screen],
        ];

        $written = false;

        // Every piece tried, not stopped at the first: each is its own file
        // with its own skip check.
        foreach ($pieces as [$kind, $suffix, $art]) {
            $written = $this->writeArtPiece($game, $serial, $kind, $suffix, $art) || $written;
        }

        return $written;
    }

    /**
     * Write one piece of a game's art, or decline to — no artwork of that
     * kind cached, or the file is already there.
     *
     * The skip check asks only about the format this build writes. A drive
     * written while covers went out as JPEG carries a _COV.jpg per game, which
     * OPL never drew; those are left where they are — deleting files from
     * somebody's drive is not this method's business — and the first run
     * after writes the .png beside each.
     */
    private function writeArtPiece(Game $game, string $serial, MediaKind $kind, string $suffix, CoverArt $art): bool
    {
        $artwork = $game->artwork($kind);

        if ($artwork === null) {
            return false;
        }

        $gate = app(LibraryPath::class);
        $path = $this->artDir.'/'.$serial.$suffix.'.'.$art->format->value;

        if (! $this->force && $gate->exists($this->console, $path)) {
            return false;
        }

        $source = Storage::disk('media')->get($artwork->path);

        if (! is_string($source) || $source === '') {
            return false;
        }

        $gate->ensureDirectory($this->console, $this->artDir);
        $gate->put($this->console, $path, $art->encode($source));

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

    /** A name with its leading OPL license ID prefix taken off, or as it was. */
    private function withoutLicenseId(string $name): string
    {
        return trim((string) preg_replace(
            '/^('.$this->serialPrefixes.')[-_][0-9]{3}\.?[0-9]{2}[.\s_-]+/i',
            '',
            $name,
        ));
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
            'Rating' => $this->starsFor($game->rating),
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
     * The provider's 0–100 score as the whole stars OPL draws, or '' for none.
     *
     * OPL shows a rating as one of six images, Rating_0 to Rating_5, named for
     * the value — so anything but a whole number in that range, our own score
     * included, falls back to the empty Rating_0.
     */
    private function starsFor(?int $rating): string
    {
        if ($rating === null) {
            return '';
        }

        return (string) max(0, min(5, (int) round($rating / 20)));
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
