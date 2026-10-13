<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Enums\FileRole;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Support\Console;
use App\Support\Scanning\DiscOrder;
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
 * But for a game of several discs, on a console the front-end plays an .m3u
 * for: its discs go into a folder of their own with a playlist we write,
 * which is what the list points at, so RetroArch can change discs and the
 * front-end shows one game (docs/adr/0008).
 *
 * What differs is each target's own: where the system folders live and what
 * they are called, where the artwork goes and under which tag, if any, the
 * game list names it.
 */
abstract class GamelistTarget implements TransferTarget
{
    use ChoosesOptions;

    /**
     * The artwork this target sends, by a name of its own: the provider media
     * types that can fill it, most preferred first, and the gamelist.xml tag
     * that points at it — none for a front-end that finds artwork by its file
     * name. Only artwork already downloaded goes; a type nobody fetched is
     * simply left out, tag and all. `label` is what to call it, when the
     * name is not; `recommended` false for one not worth fetching for.
     *
     * @return array<string, array{types: list<string>, tag?: string, label?: string, recommended?: bool}>
     */
    abstract protected function artwork(): array;

    public function artworkSlots(): array
    {
        $slots = [];

        foreach ($this->artwork() as $slot => $artwork) {
            $slots[$slot] = [
                'label' => $artwork['label'] ?? $slot,
                'types' => $artwork['types'],
                // Video is no picture, whatever slot a front-end gives it.
                'recommended' => $artwork['recommended'] ?? ! str_starts_with($artwork['types'][0] ?? '', 'video'),
            ];
        }

        return $slots;
    }

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

    /**
     * The consoles this front-end plays a multi-disc game's .m3u on. Their
     * games of several discs go as a folder with the discs and a playlist;
     * everyone else's as the library has them.
     *
     * @return list<string> console keys
     */
    protected function playlistConsoles(): array
    {
        return [];
    }

    /** The folder a multi-disc game goes into, inside the system folder, for a playlist of this name. */
    protected function setFolder(string $name): string
    {
        return $name;
    }

    /**
     * What the game list points at for a multi-disc game: its playlist.
     *
     * @param  string  $folder  the set's folder, inside the system folder
     * @param  string  $playlist  the playlist's path, inside the system folder
     */
    protected function setEntry(string $folder, string $playlist): string
    {
        return $playlist;
    }

    public function root(): array
    {
        return [$this->romsFolder()];
    }

    /**
     * The playlist of a multi-disc game, which the library may not hold and
     * which, where it does, names the discs where they lie in the library
     * rather than where they go. Everything else these front-ends read is
     * copied, and their list is merged.
     */
    public function extras(Game $game): array
    {
        $playlist = $this->layout($game)['playlist'];

        return $playlist !== null ? [$this->systemFolder($game).'/'.$playlist['path']] : [];
    }

    /** One disc a line, relative to the playlist, LF: what every core that reads one agrees on. */
    public function extra(Game $game, string $destination): ?string
    {
        $playlist = $this->layout($game)['playlist'];

        return $playlist !== null && $destination === $this->systemFolder($game).'/'.$playlist['path']
            ? implode("\n", $playlist['discs'])."\n"
            : null;
    }

    /** The playlist is the only extra, and the discs it names are the game's as it is now. */
    public function rewritesExtra(Game $game, string $destination): bool
    {
        return in_array($destination, $this->extras($game), true);
    }

    public function hint(): ?string
    {
        return null;
    }

    public function plan(Game $game): TransferPlan
    {
        $folder = $this->systemFolder($game);
        $layout = $this->layout($game);
        $files = [];

        $console = $game->console();

        foreach ($this->filesOf($game) as $file) {
            $relative = $this->relative($game, $file);

            if ($console === null || $relative === null) {
                throw new TransferRejected(__('Some of this game\'s files are outside the console\'s folder.'));
            }

            // The library's own playlist names the discs where they lie
            // there; the one this set goes with is written in its place.
            if (! isset($layout['paths'][$file->id])) {
                continue;
            }

            $files[] = new PlannedFile(
                url: route('transfers.files', ['file' => $file->id]),
                source: Location::library($console, $relative),
                destination: $folder.'/'.$layout['paths'][$file->id],
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

        $extras = array_map(fn (string $destination): array => [
            'url' => route('transfers.extra', ['target' => $this->key(), 'gameId' => $game->id, 'path' => $destination, ...$this->options()->query()]),
            'destination' => $destination,
            'rewrite' => $this->rewritesExtra($game, $destination),
        ], $this->extras($game));

        $planned = [
            ...array_map(fn (PlannedFile $file): string => $file->destination, $files),
            ...array_column($extras, 'destination'),
        ];

        return new TransferPlan($files, $this->gamelistFor($game->console), extras: $extras, replaces: $this->replaced($game, $planned));
    }

    /**
     * Where the game's other versions would be, laid out the way this one is:
     * their files and their artwork, named after them. A drive that was sent
     * the Portuguese translation before the plain copy was the one to send
     * holds both otherwise, and the front-end lists the game twice. Nothing
     * this send writes itself is among them.
     *
     * Every version, this one too, also as it went before multi-disc games
     * had folders of their own: loose beside the other games, the list
     * pointing at the first disc.
     *
     * @param  list<string>  $planned  the destinations this send writes
     * @return list<string>
     */
    private function replaced(Game $game, array $planned): array
    {
        $folder = $this->systemFolder($game);
        $paths = [];

        // This version as it went before only where it goes otherwise now:
        // the same paths would be its own artwork in slots this send left out.
        $sets = $this->layout($game)['playlist'] !== null ? [null] : [];

        foreach ([...$this->otherVersions($game), ...$sets] as $version) {
            foreach ($version !== null ? [false, true] : [true] as $loose) {
                $layout = $this->layout($game, $version, $loose);

                foreach ($layout['paths'] as $path) {
                    $paths[] = $folder.'/'.$path;
                }

                if ($layout['playlist'] !== null) {
                    $paths[] = $folder.'/'.$layout['playlist']['path'];
                }

                // Every slot, chosen or not: an earlier send may have chosen otherwise.
                $paths = [...$paths, ...array_keys($this->artworkFiles($game, version: $version, everySlot: true, loose: $loose))];
            }
        }

        return array_values(array_diff(array_unique($paths), $planned));
    }

    /**
     * Every version of the game but the one this send chooses.
     *
     * @return list<list<GameFile>>
     */
    private function otherVersions(Game $game): array
    {
        $chosen = $this->filesOf($game);
        $ids = array_map(fn (GameFile $file): int => $file->id, $chosen);

        return array_values(array_filter(
            GameVersions::of($game),
            fn (array $version): bool => ! in_array($version[0]->id, $ids, true),
        ));
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
            // The game's other versions go from the drive as this one arrives
            // (plan()'s replaces), and their entries from the list with them.
            // So does this one's entry from before it had a folder of its own.
            $entry = $this->entry($document, $game);
            $path = trim((string) $entry->getElementsByTagName('path')->item(0)?->textContent);

            $others = [
                ...array_merge(...array_map(fn (array $version): array => [
                    $this->primaryPath($game, $version),
                    $this->primaryPath($game, $version, loose: true),
                ], $this->otherVersions($game))),
                $this->primaryPath($game, loose: true),
            ];

            foreach (array_unique(array_filter($others)) as $other) {
                if ('./'.$other !== $path && isset($byPath['./'.$other])) {
                    $list->removeChild($byPath['./'.$other]);
                    unset($byPath['./'.$other]);
                }
            }

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
     * @param  list<GameFile>|null  $version  another version's files; none for the one sent
     * @return list<GameFile>
     */
    private function filesOf(Game $game, ?array $version = null): array
    {
        $files = $version ?? GameVersions::preferred($game, chain: $this->regionChain($game->console()));
        usort($files, fn (GameFile $a, GameFile $b): int => $a->path <=> $b->path);

        return $files;
    }

    /**
     * The file the game list points at, and the front-end launches: the
     * playlist of a multi-disc set, else the first disc's cuesheet, else the
     * ROM itself.
     *
     * @param  list<GameFile>|null  $version  another version's files; none for the one sent
     */
    private function primary(Game $game, ?array $version = null): ?GameFile
    {
        $files = collect($this->filesOf($game, $version));

        return $files->firstWhere('role', FileRole::Playlist)
            ?? $files->where('role', FileRole::Sheet)->sortBy(fn (GameFile $f) => [$f->disc_number ?? PHP_INT_MAX, $f->path])->first()
            ?? $files->firstWhere('role', FileRole::Rom)
            ?? $files->first();
    }

    /**
     * What the game list points at, inside the system folder: a set's
     * playlist, else the primary file; null for a game with none.
     *
     * @param  list<GameFile>|null  $version  another version's files; none for the one sent
     * @param  bool  $loose  as it went before a set had a folder of its own
     */
    private function primaryPath(Game $game, ?array $version = null, bool $loose = false): ?string
    {
        $layout = $this->layout($game, $version, $loose);

        if ($layout['playlist'] !== null) {
            return $this->setEntry($layout['playlist']['folder'], $layout['playlist']['path']);
        }

        $primary = $this->primary($game, $version);

        return $primary !== null ? ($layout['paths'][$primary->id] ?? $primary->filename) : null;
    }

    /**
     * Where each of a version's files goes inside the system folder, by id,
     * and the playlist written beside them, if any.
     *
     * A version of two discs or more, on a console this front-end plays an
     * .m3u for, goes into a folder of its own named for the game, its files
     * arranged under it as they are under the folder they share in the
     * library, with a playlist of the same name listing its discs in order.
     * The library's own playlist, if it has one, is not among the files:
     * what it names is where the discs lie in the library.
     *
     * Everything else goes where it is in the console's folder, and so does
     * every set, asked $loose — as sets went before they had folders.
     *
     * @param  list<GameFile>|null  $version  another version's files; none for the one sent
     * @return array{paths: array<int, string>, playlist: array{folder: string, path: string, discs: list<string>}|null}
     */
    private function layout(Game $game, ?array $version = null, bool $loose = false): array
    {
        $files = $this->filesOf($game, $version);
        $paths = [];

        foreach ($files as $file) {
            $relative = $this->relative($game, $file);

            if ($relative !== null) {
                $paths[$file->id] = $relative;
            }
        }

        $discs = $this->discsOf($files);

        if ($loose || count($discs) < 2 || ! in_array($game->console, $this->playlistConsoles(), true) || count($paths) !== count($files)) {
            return ['paths' => $paths, 'playlist' => null];
        }

        $playlist = collect($files)->firstWhere('role', FileRole::Playlist);
        $first = pathinfo($discs[0]->filename, PATHINFO_FILENAME);
        $name = $playlist !== null
            ? pathinfo($playlist->filename, PATHINFO_FILENAME)
            : (DiscOrder::set($first) ?? $first);

        $folder = $this->setFolder($name);

        if ($playlist !== null) {
            unset($paths[$playlist->id]);
        }

        // Under the set's folder as under the deepest folder the files share.
        $shared = $this->sharedFolder(array_values($paths));

        foreach ($paths as $id => $path) {
            $paths[$id] = $folder.'/'.($shared === '' ? $path : substr($path, strlen($shared) + 1));
        }

        return ['paths' => $paths, 'playlist' => [
            'folder' => $folder,
            'path' => $folder.'/'.$name.'.m3u',
            'discs' => array_map(fn (GameFile $disc): string => Str::after($paths[$disc->id], $folder.'/'), $discs),
        ]];
    }

    /**
     * A version's discs in order: the sheets and whole images at its top, or
     * named by its playlist. A cuesheet's tracks are its disc's, not discs.
     *
     * @param  list<GameFile>  $files
     * @return list<GameFile>
     */
    private function discsOf(array $files): array
    {
        $ids = array_map(fn (GameFile $file): int => $file->id, $files);
        $playlists = array_map(
            fn (GameFile $file): int => $file->id,
            array_filter($files, fn (GameFile $file): bool => $file->role === FileRole::Playlist),
        );

        $discs = array_values(array_filter($files, function (GameFile $file) use ($ids, $playlists): bool {
            $top = $file->parent_id === null || ! in_array($file->parent_id, $ids, true) || in_array($file->parent_id, $playlists, true);

            return $top && in_array($file->role, [FileRole::Sheet, FileRole::Rom], true);
        }));

        usort($discs, fn (GameFile $a, GameFile $b): int => ($a->disc_number ?? DiscOrder::number($a->filename)) <=> ($b->disc_number ?? DiscOrder::number($b->filename))
            ?: DiscOrder::compare($a->filename, $b->filename));

        return $discs;
    }

    /**
     * The deepest folder every one of these paths is in; '' for none.
     *
     * @param  list<string>  $paths
     */
    private function sharedFolder(array $paths): string
    {
        $shared = null;

        foreach ($paths as $path) {
            $folders = explode('/', dirname($path));
            $folders = $folders === ['.'] ? [] : $folders;

            if ($shared === null) {
                $shared = $folders;

                continue;
            }

            $common = 0;

            while ($common < min(count($shared), count($folders)) && $shared[$common] === $folders[$common]) {
                $common++;
            }

            $shared = array_slice($shared, 0, $common);
        }

        return implode('/', $shared ?? []);
    }

    /** The system folder from the drive's root. */
    private function systemFolder(Game $game): string
    {
        return $this->romsFolder().'/'.$this->system($game->console);
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
     * @param  list<GameFile>|null  $version  another version's files, to name it after; none for the one sent
     * @param  bool  $everySlot  whatever this transfer chose to send
     * @param  bool  $loose  named as a set's was before it had a folder of its own
     * @return array<string, Media> destination => media, keyed by slot when asked
     */
    private function artworkFiles(Game $game, bool $bySlot = false, ?array $version = null, bool $everySlot = false, bool $loose = false): array
    {
        $primary = $this->primaryPath($game, $version, $loose);

        if ($primary === null) {
            return [];
        }

        $system = $this->system($game->console);
        $found = [];

        foreach ($this->artwork() as $slot => ['types' => $types]) {
            // Left out of this transfer: no file, and no tag pointing at one.
            if (! $everySlot && ! $this->options()->sends($slot)) {
                continue;
            }

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
