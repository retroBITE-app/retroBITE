<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class Console
{
    public readonly string $key;
    public readonly string $name;
    public readonly string $brand;
    public readonly string $icon;
    public readonly string $fileIcon;
    public readonly string $folder;

    /** @var string[] */
    public readonly array $fileExtensions;

    /** @var string[] */
    public readonly array $biosExtensions;

    /** @var string[] */
    public readonly array $subfolders;

    /** @var string[] */
    public readonly array $excludeFiles;

    public function __construct(string $key)
    {
        $meta = config("consoles.{$key}");

        if (!is_array($meta)) {
            throw new InvalidArgumentException("Unknown console: {$key}");
        }

        $this->key            = $key;
        $this->name           = (string) Arr::get($meta, 'name', '');
        $this->brand          = (string) Arr::get($meta, 'brand', '');
        $this->icon           = (string) Arr::get($meta, 'icon', '');
        $this->fileIcon       = (string) Arr::get($meta, 'file_icon', '');
        $this->folder         = (string) Arr::get($meta, 'folder', '');
        $this->fileExtensions = (array)  Arr::get($meta, 'file_extensions', []);
        $this->biosExtensions = (array)  Arr::get($meta, 'bios_extensions', []);
        $this->subfolders     = (array)  Arr::get($meta, 'subfolders', []);
        $this->excludeFiles   = (array)  Arr::get($meta, 'exclude_files', []);
    }

    /**
     * Build a Console or return null if the key is unknown.
     */
    public static function tryFrom(?string $key): ?self
    {
        if ($key === null || !self::exists($key)) {
            return null;
        }

        return new self($key);
    }

    public static function exists(string $key): bool
    {
        return is_array(config("consoles.{$key}"));
    }

    /** @return Collection<int, self> */
    public static function all(): Collection
    {
        return Collection::make(config('consoles'))
            ->keys()
            ->map(fn(string $key) => new self($key))
            ->values();
    }

    /** @return Collection<int, self> */
    public static function allInstalled(): Collection
    {
        return self::all()->filter(fn(self $c) => $c->installed())->values();
    }

    /** @return Collection<int, self> */
    public static function allAvailable(): Collection
    {
        return self::all()->reject(fn(self $c) => $c->installed())->values();
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

    public function hasExtension(string $ext): bool
    {
        return in_array(strtolower($ext), $this->allExtensions(), true);
    }

    /**
     * Absolute filesystem path for this console, optionally joined with a subfolder.
     */
    public function path(string $subfolder = ''): string
    {
        $base = config('settings.games_path') . '/' . $this->folder;

        return $subfolder !== '' ? $base . '/' . $subfolder : $base;
    }

    public function installed(): bool
    {
        return is_dir($this->path());
    }

    /**
     * Destination options for uploads: root + installed subfolders, shaped for the Vue picker.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function uploadDirs(): array
    {
        return Collection::make($this->subfolders)
            ->map(fn(string $sub) => ['value' => $sub, 'label' => $this->folder . '/' . $sub . '/'])
            ->prepend(['value' => '', 'label' => $this->folder . '/'])
            ->filter(fn(array $dir) => is_dir($this->path($dir['value'])))
            ->values()
            ->all();
    }

    /**
     * Card-shaped payload for console lists on Dashboard and Consoles/Index.
     *
     * @param array{game_count?: int, bios_count?: int}|null $counts
     * @return array{key: string, name: string, icon: string, gameCount: int, biosCount: int}
     */
    public function toCardArray(?array $counts = null): array
    {
        return [
            'key'       => $this->key,
            'name'      => $this->name,
            'icon'      => $this->icon,
            'gameCount' => (int) Arr::get($counts ?? [], 'game_count', 0),
            'biosCount' => (int) Arr::get($counts ?? [], 'bios_count', 0),
        ];
    }

    /**
     * Network-share-shaped payload for the Dashboard network panel.
     *
     * @return array{key: string, name: string, folder: string, icon: string|null}
     */
    public function toShareArray(): array
    {
        return [
            'key'    => $this->key,
            'name'   => $this->name,
            'folder' => $this->folder,
            'icon'   => $this->icon !== '' ? $this->icon : null,
        ];
    }
}
