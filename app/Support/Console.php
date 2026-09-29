<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Layouts\Layouts;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class Console
{
    public readonly string $key;

    public readonly string $name;

    public readonly string $brand;

    /**
     * The year the machine first reached anyone, or null.
     *
     * Null is not "unknown": it is the answer for the entries here that are a
     * front-end or an emulator rather than a machine — MAME, ScummVM, WINE and
     * their like cover many systems and were never released as one.
     *
     * Where a system shipped under different names in different regions the
     * year is the earliest of them, so the Famicom's 1983 stands for the NES
     * and the Mark III's 1985 for the Master System.
     */
    public readonly ?int $released;

    public readonly string $icon;

    public readonly string $fileIcon;

    public readonly string $folder;

    /** @var string[] */
    public readonly array $fileExtensions;

    /** @var string[] */
    public readonly array $biosExtensions;

    /**
     * Images this console's toolbox can read inside.
     *
     * A subset of fileExtensions: a compressed container is one of this
     * console's files and is still not one anything can be read out of.
     * Empty for the 134 consoles with no toolbox.
     *
     * @var string[]
     */
    public readonly array $toolboxFileExtensions;

    /** @var string[] */
    public readonly array $excludeFiles;

    public readonly ?int $screenscraperId;

    /**
     * The on-disk arrangements this console offers, by key.
     *
     * A console that declares none offers the custom layout alone, which reads
     * everything — so the 134 console files that say nothing about layouts
     * behave exactly as they did before layouts existed.
     *
     * @var string[]
     */
    public readonly array $layouts;

    /** The layout to assume until somebody says otherwise. */
    public readonly string $defaultLayout;

    /**
     * The conversions offered on this console, by key in config/converters.php,
     * in the order the Conversion page lists them.
     *
     * A console that declares none offers no conversions: a format is only
     * ever written for a console whose own file says its frontends read it.
     *
     * @var list<string>
     */
    public readonly array $converters;

    /**
     * RetroAchievements' ConsoleID, which is also RAHasher's systemid.
     *
     * Unrelated to screenscraperId despite both being small integers, and the
     * ranges overlap: NES is 3 at ScreenScraper and 7 here, while 3 here is the
     * Super Nintendo. Null for the consoles RetroAchievements does not cover.
     */
    public readonly ?int $retroachievementsId;

    public function __construct(string $key)
    {
        $meta = config("consoles.{$key}");

        if (! is_array($meta)) {
            throw new InvalidArgumentException("Unknown console: {$key}");
        }

        $ssId = Arr::get($meta, 'screenscraper_id');
        $raId = Arr::get($meta, 'retroachievements_id');

        $this->key = $key;
        $this->name = (string) Arr::get($meta, 'name', '');
        $this->brand = (string) Arr::get($meta, 'brand', '');
        $this->released = ($year = Arr::get($meta, 'released')) !== null ? (int) $year : null;
        $this->icon = (string) Arr::get($meta, 'icon', '');
        $this->fileIcon = (string) Arr::get($meta, 'file_icon', '');
        $this->folder = (string) Arr::get($meta, 'folder', '');
        $this->fileExtensions = (array) Arr::get($meta, 'file_extensions', []);
        $this->biosExtensions = (array) Arr::get($meta, 'bios_extensions', []);
        $this->toolboxFileExtensions = (array) Arr::get($meta, 'toolbox_file_extensions', []);
        $this->excludeFiles = (array) Arr::get($meta, 'exclude_files', []);
        $this->screenscraperId = $ssId !== null ? (int) $ssId : null;
        $this->layouts = (array) Arr::get($meta, 'layouts', [Layouts::FALLBACK]);
        $this->defaultLayout = (string) Arr::get($meta, 'default_layout', Layouts::FALLBACK);
        $this->converters = array_values(array_filter((array) Arr::get($meta, 'converters', []), 'is_string'));
        $this->retroachievementsId = $raId !== null ? (int) $raId : null;
    }

    /**
     * Build a Console or return null if the key is unknown.
     */
    public static function tryFrom(?string $key): ?self
    {
        if ($key === null || ! self::exists($key)) {
            return null;
        }

        return new self($key);
    }

    /**
     * Is this a known console key?
     */
    public static function exists(string $key): bool
    {
        return is_array(config("consoles.{$key}"));
    }

    /** @return Collection<int, self> */
    public static function all(): Collection
    {
        return Collection::make((array) config('consoles'))
            ->keys()
            ->map(fn (string $key) => new self($key))
            ->values();
    }

    /** @return Collection<int, self> */
    public static function allInstalled(): Collection
    {
        return self::all()->filter(fn (self $c) => $c->installed())->values();
    }

    /** @return Collection<int, self> */
    public static function allAvailable(): Collection
    {
        return self::all()->reject(fn (self $c) => $c->installed())->values();
    }

    /**
     * Flat list of every allowed file extension (case-insensitive, lowercase).
     *
     * @return string[]
     */
    public function allExtensions(): array
    {
        return Arr::collapse([$this->fileExtensions, $this->biosExtensions]);
    }

    /**
     * Does this console accept files with that extension?
     */
    public function hasExtension(string $ext): bool
    {
        return in_array(strtolower($ext), $this->allExtensions(), true);
    }

    /**
     * Is a file with that extension a game, rather than a firmware image?
     *
     * Narrower than hasExtension() on purpose: file_extensions wins over
     * bios_extensions where a console lists the same one in both — PS2 has
     * .bin games and a .bin BIOS — so a count taken here agrees with what the
     * scanner actually imports.
     */
    public function playsExtension(string $ext): bool
    {
        return in_array(strtolower($ext), array_map('strtolower', $this->fileExtensions), true);
    }

    /**
     * Absolute filesystem path for this console, optionally joined with a subfolder.
     */
    public function path(string $subfolder = ''): string
    {
        $base = config('settings.games_path').'/'.$this->folder;

        return $subfolder !== '' ? $base.'/'.$subfolder : $base;
    }

    /**
     * Has this console had its directory created?
     */
    public function installed(): bool
    {
        return is_dir($this->path());
    }

    /**
     * Card-shaped payload for the console list and the layout's sidebar.
     *
     * @param  array{game_count?: int, bios_count?: int, identified_count?: int, bytes?: int}|null  $counts
     * @return array{key: string, name: string, icon: string, path: string, game_count: int, bios_count: int, identified_count: int, bytes: int}
     */
    public function toCardArray(?array $counts = null): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'icon' => $this->icon,
            'path' => $this->libraryPath(),
            'game_count' => (int) Arr::get($counts ?? [], 'game_count', 0),
            'bios_count' => (int) Arr::get($counts ?? [], 'bios_count', 0),
            'identified_count' => (int) Arr::get($counts ?? [], 'identified_count', 0),
            'bytes' => (int) Arr::get($counts ?? [], 'bytes', 0),
        ];
    }

    /**
     * This console's folder as the user sees it, e.g. "games/gc" — relative to
     * the library root, since the absolute path is a container detail.
     */
    public function libraryPath(): string
    {
        return basename((string) config('settings.games_path')).'/'.$this->folder;
    }

    /**
     * Presentation fields the frontend needs, rather than the whole config entry.
     *
     * @return array{name: string, icon: string, file_icon: string, folder: string, cover_aspect: ?string}
     */
    public function toMetaArray(): array
    {
        $meta = (array) config("consoles.{$this->key}");

        return [
            'name' => $this->name,
            'icon' => $this->icon,
            'file_icon' => $this->fileIcon,
            'folder' => $this->folder,
            'path' => $this->libraryPath(),
            'cover_aspect' => Arr::get($meta, 'cover_aspect'),
        ];
    }

    /**
     * Display path for a subfolder, e.g. "ps2/DVD/" or "ps2/" for the root.
     */
    public function folderLabel(string $subfolder = ''): string
    {
        return $subfolder === ''
            ? $this->folder.'/'
            : $this->folder.'/'.$subfolder.'/';
    }

    /**
     * Picker options for the console root plus each subfolder given.
     *
     * @param  string[]  $subfolders
     * @return array<int, array{value: string, label: string}>
     */
    public function folderOptions(array $subfolders): array
    {
        return Collection::make($subfolders)
            ->map(fn (string $sub) => ['value' => $sub, 'label' => $this->folderLabel($sub)])
            ->prepend(['value' => '', 'label' => $this->folderLabel()])
            ->values()
            ->all();
    }

    /**
     * Network-share-shaped payload for the Dashboard network panel.
     *
     * @return array{key: string, name: string, folder: string, icon: string|null}
     */
    public function toShareArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'folder' => $this->folder,
            'icon' => $this->icon !== '' ? $this->icon : null,
        ];
    }
}
