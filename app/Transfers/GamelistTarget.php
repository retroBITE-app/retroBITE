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
 * A front-end that reads an EmulationStation gamelist.xml: Batocera, Recalbox,
 * RetroPie, ES-DE and, for its names and descriptions, Daijishou.
 *
 * What they share is here: one version of the game, its files in the
 * arrangement they have in the library under {roms folder}/{system}/, and a
 * <gameList> of <game> entries whose paths are relative to the system folder
 * and start with ./, a rating from 0 to 1, and dates as YYYYMMDDTHHMMSS.
 *
 * What differs is each target's own: where the system folders live and what
 * they are called, where the artwork goes and under which tag, if any, the
 * game list names it.
 */
abstract class GamelistTarget implements TransferTarget
{
    /**
     * The artwork this target sends, by a name of its own: the provider media
     * types that can fill it, most preferred first, and the gamelist.xml tag
     * that points at it — none for a front-end that finds artwork by its file
     * name. Only artwork already downloaded goes; a type nobody fetched is
     * simply left out, tag and all.
     *
     * @return array<string, array{types: list<string>, tag?: string}>
     */
    abstract protected function artwork(): array;

    /**
     * Where one piece of artwork goes, from the drive's root.
     *
     * @param  string  $system  the system folder's name
     * @param  string  $primary  the file the game list points at, relative to the system folder
     */
    abstract protected function artworkPath(string $system, string $slot, string $primary, Media $media): string;

    /** The folder the system folders are in, from the drive's root. */
    abstract protected function romsFolder(): string;

    /**
     * System folders named otherwise than the console's key. Ours were named
     * after Batocera's systems, and most front-ends agree on most of them.
     *
     * @return array<string, string> console key => system folder
     */
    protected function systems(): array
    {
        return [];
    }

    /** Every console: a front-end with no system for one simply never shows it. */
    public function supports(Console $console): bool
    {
        return true;
    }

    public function system(string $console): string
    {
        return $this->systems()[$console] ?? $console;
    }

    public function gamelistFor(string $console): string
    {
        return $this->romsFolder().'/'.$this->system($console).'/gamelist.xml';
    }

    public function root(): array
    {
        return [$this->romsFolder()];
    }

    /** None: everything these front-ends read is copied, and their list is merged. */
    public function extras(Game $game): array
    {
        return [];
    }

    public function extra(Game $game, string $destination): ?string
    {
        return null;
    }

    public function hint(): ?string
    {
        return null;
    }

    public function plan(Game $game): TransferPlan
    {
        $system = $this->system($game->console);
        $folder = $this->romsFolder().'/'.$system;
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
                destination: $folder.'/'.$relative,
                size: (int) $file->size_bytes,
            );
        }

        $sent = [];

        foreach ($this->artworkFiles($game) as $destination => $media) {
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

        return new TransferPlan($files, $this->gamelistFor($game->console));
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
     * What this game's <game> entry holds, in order; null and empty values
     * are left out. Built from what the provider told us.
     *
     * @param  array<string, string>  $artwork  tag => path relative to the system folder
     * @return array<string, scalar|null>
     */
    protected function fields(Game $game, ?string $path, array $artwork): array
    {
        return [
            'path' => $path,
            'name' => $game->title,
            'desc' => $game->description,
            ...$artwork,
            // Ours is out of a hundred; EmulationStation's from 0 to 1.
            'rating' => $game->rating !== null ? rtrim(rtrim(number_format($game->rating / 100, 2, '.', ''), '0'), '.') : null,
            'releasedate' => $this->releaseDate($game->release_date),
            'developer' => $game->developer,
            'publisher' => $game->publisher,
            // The provider writes a trail ("Platform / Fighter Scrolling");
            // the game list shows one genre.
            'genre' => $game->genre !== null ? trim((string) Str::before($game->genre, ',')) : null,
            'players' => $this->players($game->players),
        ];
    }

    /**
     * The file the list points at without its extension, which is what
     * artwork is named after — with the folders it is in, or without.
     */
    protected function stem(string $primary, bool $folders = false): string
    {
        $directory = pathinfo($primary, PATHINFO_DIRNAME);
        $name = pathinfo($primary, PATHINFO_FILENAME);

        return $folders && $directory !== '.' ? $directory.'/'.$name : $name;
    }

    /**
     * The present files of the one version sent: a game holding several
     * regions or revisions sends one of them, so the front-end shows one
     * entry and not the others as nameless games of their own. See
     * GameVersions.
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
     * The file the game list points at, and the front-end launches: the
     * playlist of a multi-disc set, else the first disc's cuesheet, else the
     * ROM itself.
     */
    private function primary(Game $game): ?GameFile
    {
        $files = collect($this->filesOf($game));

        return $files->firstWhere('role', FileRole::Playlist)
            ?? $files->where('role', FileRole::Sheet)->sortBy(fn (GameFile $f) => [$f->disc_number ?? PHP_INT_MAX, $f->path])->first()
            ?? $files->firstWhere('role', FileRole::Rom)
            ?? $files->first();
    }

    /** The primary file's path inside the system folder, or null for a game with none. */
    private function primaryPath(Game $game): ?string
    {
        $primary = $this->primary($game);

        return $primary !== null ? ($this->relative($game, $primary) ?? $primary->filename) : null;
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
     * The artwork this game has for each slot, in slot order, with where it
     * goes. Artwork goes only for a game with a file to name it after.
     *
     * @return array<string, Media> destination => media, keyed by slot when asked
     */
    private function artworkFiles(Game $game, bool $bySlot = false): array
    {
        $primary = $this->primaryPath($game);

        if ($primary === null) {
            return [];
        }

        $system = $this->system($game->console);
        $found = [];

        foreach ($this->artwork() as $slot => ['types' => $types]) {
            $media = $game->artworkOfTypes($types);

            if ($media !== null) {
                $found[$bySlot ? $slot : $this->artworkPath($system, $slot, $primary, $media)] = $media;
            }
        }

        return $found;
    }

    /** This game's <game> entry. */
    private function entry(DOMDocument $document, Game $game): DOMElement
    {
        $primary = $this->primaryPath($game);
        $system = $this->system($game->console);
        $folder = $this->romsFolder().'/'.$system.'/';
        $slots = $this->artwork();
        $artwork = [];

        foreach ($primary !== null ? $this->artworkFiles($game, bySlot: true) : [] as $slot => $media) {
            $tag = $slots[$slot]['tag'] ?? null;
            $destination = $this->artworkPath($system, $slot, (string) $primary, $media);

            // A tag can only point inside the system folder, where ./ starts.
            if ($tag !== null && str_starts_with($destination, $folder)) {
                $artwork[$tag] = './'.substr($destination, strlen($folder));
            }
        }

        $element = $document->createElement('game');

        foreach ($this->fields($game, $primary !== null ? './'.$primary : null, $artwork) as $name => $value) {
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

    /** The provider's "1-2" as the highest count, which is what the front-ends filter by. */
    private function players(?string $players): ?string
    {
        if ($players === null || ! preg_match_all('/\d+/', $players, $numbers)) {
            return null;
        }

        return (string) max(array_map('intval', $numbers[0]));
    }
}
