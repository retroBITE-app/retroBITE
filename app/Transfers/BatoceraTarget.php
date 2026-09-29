<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Enums\FileRole;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Support\Console;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;

/**
 * Batocera: games under roms/{system}/, described in roms/{system}/gamelist.xml.
 *
 * The system folder is the console's own key — ours were named after
 * Batocera's systems to begin with. A game's files keep the arrangement they
 * have in the library, relative to the console's folder, so a multi-disc set
 * arrives as its playlist, cuesheets and tracks, and Batocera launches the
 * playlist. Its artwork goes to images/ beside it, one file per kind Batocera
 * knows ({@see ARTWORK}), named after the file the game list points at.
 *
 * gamelist.xml is the format Batocera's EmulationStation reads: a <gameList>
 * of <game> entries whose paths are relative to the system folder and start
 * with ./, a rating from 0 to 1, and dates as YYYYMMDDTHHMMSS.
 */
final class BatoceraTarget implements TransferTarget
{
    /**
     * The artwork Batocera shows, by gamelist.xml tag: the suffix its file
     * takes in images/, and the provider media types that can fill it, most
     * preferred first. Only artwork already downloaded goes; a type nobody
     * fetched is simply left out, tag and all.
     *
     * `label` is what Batocera's game artwork setting calls the tag.
     *
     * @var array<string, array{suffix: string, label: string, types: list<string>}>
     */
    public const ARTWORK = [
        // The tags are named backwards from what they hold, as EmulationStation
        // has them: <thumbnail> is the box, <image> the screenshot. The files
        // are named for the tag, as Batocera's own scraper names them: with
        // <image> empty, Batocera looks for {name}-image.png itself, and a box
        // under that name would show twice in a theme that also shows the box.
        'thumbnail' => ['suffix' => 'thumb', 'label' => 'boxart', 'types' => ['box-2D', 'box-3D']],
        // Never a mix: themes such as Iconic build their own mix out of
        // <image>, <thumbnail> and <marquee>, and a mix in <image> shows the
        // box and the logo twice. A title screen is still a frame of the game.
        'image' => ['suffix' => 'image', 'label' => 'image', 'types' => ['ss', 'sstitle']],
        'marquee' => ['suffix' => 'marquee', 'label' => 'logo', 'types' => ['wheel', 'wheel-hd', 'wheel-carbon', 'wheel-steel', 'screenmarqueesmall', 'screenmarquee']],
        'fanart' => ['suffix' => 'fanart', 'label' => 'fanart', 'types' => ['fanart']],
        'boxback' => ['suffix' => 'boxback', 'label' => 'box back', 'types' => ['box-2D-back']],
        'cartridge' => ['suffix' => 'cartridge', 'label' => 'cartridge', 'types' => ['support-2D']],
        'titleshot' => ['suffix' => 'titleshot', 'label' => 'title shot', 'types' => ['sstitle']],
        'mix' => ['suffix' => 'mix', 'label' => 'mix', 'types' => ['mixrbv2', 'mixrbv1']],
        'manual' => ['suffix' => 'manual', 'label' => 'manual', 'types' => ['manuel']],
    ];

    /**
     * Types whose file is named for what it is rather than for the tag it
     * fills: a title screen standing in for a missing screenshot is still a
     * title shot, and goes once for both tags. The files are there for
     * somebody to find and replace by hand, so the name has to say what is
     * in them.
     *
     * @var array<string, string>
     */
    private const SUFFIX_BY_TYPE = [
        'sstitle' => 'titleshot',
    ];

    /**
     * Where each provider media type can end up in Batocera, by its setting's
     * name — for the media settings screen.
     *
     * @return array<string, string> type => label
     */
    public static function artworkLabels(): array
    {
        $labels = [];

        foreach (self::ARTWORK as ['label' => $label, 'types' => $types]) {
            foreach ($types as $type) {
                $labels[$type][] = $label;
            }
        }

        // A title screen can be the image and the title shot at once.
        return array_map(fn (array $names): string => implode(', ', $names), $labels);
    }

    /**
     * What to fetch for a Batocera box: the preferred type of each tag the
     * game list writes, and not its fallbacks. Batocera shows one picture per
     * tag, and every type switched on is another download for every game.
     *
     * @return list<string>
     */
    public static function recommendedTypes(): array
    {
        return array_values(array_unique(array_map(
            fn (array $artwork): string => $artwork['types'][0],
            self::ARTWORK,
        )));
    }

    public function key(): string
    {
        return 'batocera';
    }

    public function label(): string
    {
        return 'Batocera';
    }

    /** Every console: ours were named after Batocera's systems to begin with. */
    public function supports(Console $console): bool
    {
        return true;
    }

    /**
     * roms/ at the top of the drive, or under batocera/ — the layout of an
     * external drive is not documented, so both are recognised.
     */
    public function root(): array
    {
        return ['roms', 'batocera/roms'];
    }

    /** None: everything Batocera reads is copied, and its list is merged. */
    public function extras(Game $game): array
    {
        return [];
    }

    public function extra(Game $game, string $destination): ?string
    {
        return null;
    }

    public function plan(Game $game): TransferPlan
    {
        $system = 'roms/'.$game->console;
        $files = [];

        $console = $game->console();

        foreach ($this->filesOf($game) as $file) {
            $relative = $this->relative($game, $file);

            if ($console === null || $relative === null) {
                throw new TransferRejected(__('Some of this game\'s files are outside the console\'s folder.'));
            }

            $files[] = new PlannedFile(
                url: route('transfers.files', ['file' => $file->id]),
                source: Location::library($console, $relative),
                destination: $system.'/'.$relative,
                size: (int) $file->size_bytes,
            );
        }

        $primary = $this->primary($game);

        $sent = [];

        foreach ($primary !== null ? $this->artworkOf($game) : [] as $tag => $media) {
            $destination = $system.'/'.$this->imagePath($game, $primary, $tag, $media);

            // One file for two tags — a title screen that is also the image — goes once.
            if (isset($sent[$destination])) {
                continue;
            }

            $sent[$destination] = true;
            $files[] = new PlannedFile(
                url: $media->url(),
                source: Location::media($media->path),
                destination: $destination,
                size: (int) $media->size_bytes,
            );
        }

        return new TransferPlan($files, $system.'/gamelist.xml');
    }

    public function mergeGamelist(?string $existing, Game ...$games): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;

        if ($existing !== null && trim($existing) !== '') {
            $previous = libxml_use_internal_errors(true);
            $loaded = $document->loadXML($existing);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if (! $loaded || $document->documentElement?->nodeName !== 'gameList') {
                throw new TransferRejected(__('The gamelist.xml on the drive could not be read, so it was left as it is.'));
            }
        } else {
            $document->appendChild($document->createElement('gameList'));
        }

        /** @var DOMElement $list */
        $list = $document->documentElement;

        // Read once, however many games are merged: a console's worth, one
        // at a time, would parse a list that grows with every game.
        $byPath = [];

        foreach ((new DOMXPath($document))->query('/gameList/game') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $byPath[trim((string) $node->getElementsByTagName('path')->item(0)?->textContent)] ??= $node;
            }
        }

        foreach ($games as $game) {
            $entry = $this->entry($document, $game);
            $path = trim((string) $entry->getElementsByTagName('path')->item(0)?->textContent);

            if (isset($byPath[$path])) {
                $list->replaceChild($entry, $byPath[$path]);
            } else {
                $list->appendChild($entry);
            }

            $byPath[$path] = $entry;
        }

        return (string) $document->saveXML();
    }

    /**
     * The present files of the one version sent: a game holding several
     * regions or revisions sends one of them, so Batocera shows one entry
     * and not the others as nameless games of their own. See GameVersions.
     *
     * @return list<GameFile>
     */
    private function filesOf(Game $game): array
    {
        $files = GameVersions::preferred($game);
        usort($files, fn (GameFile $a, GameFile $b): int => $a->path <=> $b->path);

        return $files;
    }

    /**
     * The file the game list points at, and Batocera launches: the playlist of
     * a multi-disc set, else the first disc's cuesheet, else the ROM itself.
     */
    private function primary(Game $game): ?GameFile
    {
        $files = collect($this->filesOf($game));

        return $files->firstWhere('role', FileRole::Playlist)
            ?? $files->where('role', FileRole::Sheet)->sortBy(fn (GameFile $f) => [$f->disc_number ?? PHP_INT_MAX, $f->path])->first()
            ?? $files->firstWhere('role', FileRole::Rom)
            ?? $files->first();
    }

    /**
     * A file's path inside the console's folder, as it will be inside the
     * system folder; null for one outside it, which cannot be read through
     * the gate.
     */
    private function relative(Game $game, GameFile $file): ?string
    {
        $console = $game->console();
        $folder = $console !== null ? trim((string) ConsoleSourceFolder::pathFor($console), '/') : '';

        return $folder !== '' && str_starts_with($file->path, $folder.'/')
            ? substr($file->path, strlen($folder) + 1)
            : null;
    }

    /**
     * The artwork this game has for each tag Batocera knows, in tag order.
     *
     * @return array<string, Media>
     */
    private function artworkOf(Game $game): array
    {
        $found = [];

        foreach (self::ARTWORK as $tag => ['types' => $types]) {
            $media = $game->artworkOfTypes($types);

            if ($media !== null) {
                $found[$tag] = $media;
            }
        }

        return $found;
    }

    /** images/{name of the file the list points at}-{what it is}.{extension} */
    private function imagePath(Game $game, GameFile $primary, string $tag, Media $media): string
    {
        $suffix = self::SUFFIX_BY_TYPE[$media->screenscraper_type] ?? self::ARTWORK[$tag]['suffix'];

        return 'images/'.pathinfo($this->relative($game, $primary) ?? $primary->filename, PATHINFO_FILENAME).'-'.$suffix.'.'.$media->extension;
    }

    /** This game's <game> entry, built from what the provider told us. */
    private function entry(DOMDocument $document, Game $game): DOMElement
    {
        $primary = $this->primary($game);
        $artwork = [];

        foreach ($primary !== null ? $this->artworkOf($game) : [] as $tag => $media) {
            $artwork[$tag] = './'.$this->imagePath($game, $primary, $tag, $media);
        }

        $fields = [
            'path' => $primary !== null ? './'.($this->relative($game, $primary) ?? $primary->filename) : null,
            'name' => $game->title,
            'desc' => $game->description,
            ...$artwork,
            // Ours is out of a hundred; Batocera's from 0 to 1.
            'rating' => $game->rating !== null ? rtrim(rtrim(number_format($game->rating / 100, 2, '.', ''), '0'), '.') : null,
            'releasedate' => $this->releaseDate($game->release_date),
            'developer' => $game->developer,
            'publisher' => $game->publisher,
            // The provider writes a trail ("Platform / Fighter Scrolling");
            // Batocera shows one genre.
            'genre' => $game->genre !== null ? trim((string) Str::before($game->genre, ',')) : null,
            'players' => $this->players($game->players),
        ];

        $element = $document->createElement('game');

        foreach ($fields as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $child = $document->createElement($name);
            $child->appendChild($document->createTextNode((string) $value));
            $element->appendChild($child);
        }

        return $element;
    }

    /** "1994-11-01", "1994-11" or "1994" as YYYYMMDDT000000. */
    private function releaseDate(?string $date): ?string
    {
        if ($date === null || ! preg_match('/^(\d{4})(?:-(\d{2}))?(?:-(\d{2}))?/', $date, $parts)) {
            return null;
        }

        return $parts[1].($parts[2] ?? '01').($parts[3] ?? '01').'T000000';
    }

    /** The provider's "1-2" as the highest count, which is what Batocera filters by. */
    private function players(?string $players): ?string
    {
        if ($players === null || ! preg_match_all('/\d+/', $players, $numbers)) {
            return null;
        }

        return (string) max(array_map('intval', $numbers[0]));
    }
}
