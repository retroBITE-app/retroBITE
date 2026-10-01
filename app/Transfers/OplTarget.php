<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Enums\MediaKind;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Support\Console;
use App\Support\Layouts\OplLayout;
use App\Support\Scanning\LibraryFolders;
use App\Tools\ConsoleTool\PS2;
use Illuminate\Support\Str;

/**
 * Open PS2 Loader: discs in DVD/ or CD/ at the top of the drive, a config per
 * game in CFG/<serial>.cfg, and its art in ART/<serial>_COV.png and kin.
 *
 * The discs keep their library names. Which folder each goes in is the
 * library's own choice when it is laid out for OPL already, and otherwise the
 * disc's size: OPL reads CD/ and DVD/ as different media, and anything bigger
 * than an 80-minute CD is a DVD. The config and the art are made by the PS2
 * toolbox's own code as they are written, never stored, and never replace
 * one already on the drive — somebody may have tuned it there. OPL keeps no game list:
 * it reads the folders.
 */
final class OplTarget implements TransferTarget
{
    use ChoosesOptions;

    /** The formats OPL reads a disc in. */
    public const FORMATS = ['iso', 'cso', 'zso'];

    /** The most an 80-minute CD holds, in bytes. */
    public const CD_BYTES = 737_280_000;

    public function __construct(
        private readonly PS2 $ps2,
        private readonly OplLayout $layout,
    ) {}

    public function key(): string
    {
        return 'opl';
    }

    public function label(): string
    {
        return 'Open PS2 Loader';
    }

    public function supports(Console $console): bool
    {
        return $console->key === 'ps2';
    }

    public function hint(): ?string
    {
        return null;
    }

    /** OPL's art, by the kind the PS2 toolbox makes each piece from. */
    public function artworkSlots(): array
    {
        $slots = [];

        foreach ($this->ps2->artPieces() as $kind) {
            $slots[$kind->value] = ['label' => $kind->label(), 'types' => array_values($kind->screenScraperTypes())];
        }

        return $slots;
    }

    /** The drive's own root: OPL looks for DVD/ and CD/ there and nowhere else. */
    public function root(): array
    {
        return [];
    }

    public function plan(Game $game): TransferPlan
    {
        $console = $game->console();

        if ($console === null || ! $this->supports($console)) {
            throw new TransferRejected(__('Open PS2 Loader plays PlayStation 2 games only.'));
        }

        $discs = GameVersions::preferred($game, self::FORMATS, $this->regionChain($console));

        if ($discs === []) {
            throw new TransferRejected(__('Open PS2 Loader reads ISO, CSO or ZSO. Convert this game first.'));
        }

        $files = [];

        foreach ($discs as $disc) {
            $relative = $this->relative($console, $disc);

            if ($relative === null) {
                throw new TransferRejected(__('Some of this game\'s files are outside the console\'s folder.'));
            }

            $files[] = new PlannedFile(
                url: route('transfers.files', ['file' => $disc->id]),
                source: Location::library($console, $relative),
                destination: $this->folderFor($disc, $relative).'/'.$disc->filename,
                size: (int) $disc->size_bytes,
            );
        }

        $extras = array_map(function (string $destination) use ($game): array {
            return [
                'url' => route('transfers.extra', ['target' => $this->key(), 'gameId' => $game->id, 'path' => $destination, ...$this->options()->query()]),
                'destination' => $destination,
            ];
        }, $this->extras($game));

        // The game's other discs OPL could read, where this layout would put
        // them: replaced by this one, or OPL lists the game twice. Its config
        // and art are named after the serial, and stay.
        $sent = array_map(fn (GameFile $disc): int => $disc->id, $discs);
        $planned = array_map(fn (PlannedFile $file): string => $file->destination, $files);
        $replaces = [];

        foreach (GameVersions::of($game) as $version) {
            foreach ($version as $disc) {
                $relative = $this->relative($console, $disc);

                if (in_array($disc->id, $sent, true) || $relative === null || ! in_array(Str::lower((string) $disc->extension), self::FORMATS, true)) {
                    continue;
                }

                $replaces[] = $this->folderFor($disc, $relative).'/'.$disc->filename;
            }
        }

        return new TransferPlan($files, null, extras: $extras, replaces: array_values(array_diff(array_unique($replaces), $planned)));
    }

    /**
     * The config, and every piece of art the game has, where the toolbox
     * names them; none for a game with no serial to name them by.
     */
    public function extras(Game $game): array
    {
        $serial = $this->ps2->serialFor($game);

        if ($serial === null) {
            return [];
        }

        $art = collect($this->sentArtPieces())
            ->filter(function (MediaKind $kind) use ($game): bool {
                return $game->artwork($kind) !== null;
            })
            ->map(function (MediaKind $kind) use ($serial): string {
                return $this->ps2->artPathFor($serial, $kind);
            })
            ->values()
            ->all();

        return [$this->ps2->configPathFor($serial), ...$art];
    }

    /** Made by the PS2 toolbox, as its own export writes them into a library. */
    public function extra(Game $game, string $destination): ?string
    {
        $serial = $this->ps2->serialFor($game);

        if ($serial === null) {
            return null;
        }

        if ($destination === $this->ps2->configPathFor($serial)) {
            return $this->ps2->configFor($game);
        }

        $kind = collect($this->sentArtPieces())->first(function (MediaKind $kind) use ($serial, $destination): bool {
            return $this->ps2->artPathFor($serial, $kind) === $destination;
        });

        return $kind instanceof MediaKind ? $this->ps2->artFor($game, $kind) : null;
    }

    /**
     * The pieces of art this transfer sends.
     *
     * @return list<MediaKind>
     */
    private function sentArtPieces(): array
    {
        return array_values(array_filter(
            $this->ps2->artPieces(),
            fn (MediaKind $kind): bool => $this->options()->sends($kind->value),
        ));
    }

    /** None: OPL reads its folders. */
    public function gamelistFor(string $console): ?string
    {
        return null;
    }

    public function mergeGamelist(?string $existing, Game ...$games): string
    {
        throw new TransferRejected(__('Open PS2 Loader keeps no game list.'));
    }

    /**
     * The disc's size uncompressed, as OPL will see it: an ISO's own size, a
     * CSO's or ZSO's as its header states it — both formats keep the original
     * size as a little-endian 64-bit number eight bytes in. Null when the
     * file cannot be read.
     */
    public static function discBytes(string $path): ?int
    {
        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'iso') {
            $size = @filesize($path);

            return $size === false ? null : $size;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $header = fread($handle, 16);
        } finally {
            fclose($handle);
        }

        if (! is_string($header) || strlen($header) < 16 || ! in_array(substr($header, 0, 4), ['CISO', 'ZISO'], true)) {
            return null;
        }

        $unpacked = unpack('Pbytes', $header, 8);

        return $unpacked === false ? null : (int) $unpacked['bytes'];
    }

    /**
     * CD or DVD: the folder the library already keeps it in, when that is
     * one of the two, else by how much the disc holds. A disc whose size
     * cannot be read goes to DVD/, where nearly every PS2 game belongs.
     */
    private function folderFor(GameFile $disc, string $relative): string
    {
        $folder = Str::upper(Str::before($relative, '/'));

        if (Str::contains($relative, '/') && in_array($folder, $this->layout->gameDirectories(), true)) {
            return $folder;
        }

        $bytes = self::discBytes(LibraryFolders::root().'/'.$disc->path);

        return $bytes !== null && $bytes <= self::CD_BYTES ? $this->layout->cdDirectory() : $this->layout->dvdDirectory();
    }

    /** A file's path inside the console's folder; null for one outside it. */
    private function relative(Console $console, GameFile $file): ?string
    {
        $folder = trim((string) ConsoleSourceFolder::pathFor($console), '/');

        return $folder !== '' && Str::startsWith($file->path, $folder.'/')
            ? Str::after($file->path, $folder.'/')
            : null;
    }
}
