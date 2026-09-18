<?php

declare(strict_types=1);

namespace App\Resources;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Typed reader over one file in config/consoles/. Consoles are editable config
 * rather than a closed vocabulary, so this is a value object and not an enum.
 */
final class ConsoleResource
{
    public readonly string $key;

    public readonly int $order;

    public readonly ?string $name;

    public readonly ?string $brand;

    public readonly ?string $icon;

    public readonly ?string $fileIcon;

    public readonly ?string $folder;

    public readonly ?string $coverAspect;

    public readonly ?int $coverHeight;

    public readonly ?int $screenscraperId;

    /** @var string[] */
    public readonly array $fileExtensions;

    /** @var string[] */
    public readonly array $biosExtensions;

    /** @var string[] */
    public readonly array $excludeFiles;

    /** @var array<string, mixed> */
    private readonly array $meta;

    /**
     * A console file may omit any key: scalars then read as null, lists as [].
     *
     * @param  array<string, mixed>  $meta
     */
    private function __construct(string $key, array $meta)
    {
        $this->meta = $meta;
        $this->key = $key;
        $this->order = (int) Arr::get($meta, 'order', PHP_INT_MAX);
        $this->name = self::asString($meta, 'name');
        $this->brand = self::asString($meta, 'brand');
        $this->icon = self::asString($meta, 'icon');
        $this->fileIcon = self::asString($meta, 'file_icon');
        $this->folder = self::asString($meta, 'folder');
        $this->coverAspect = self::asString($meta, 'cover_aspect');
        $this->coverHeight = self::asInt($meta, 'cover_height');
        $this->screenscraperId = self::asInt($meta, 'screenscraper_id');
        $this->fileExtensions = (array) Arr::get($meta, 'file_extensions', []);
        $this->biosExtensions = (array) Arr::get($meta, 'bios_extensions', []);
        $this->excludeFiles = (array) Arr::get($meta, 'exclude_files', []);
    }

    /**
     * Wrap one console, or null when the key names no file in config/consoles/.
     */
    public static function make(?string $key): ?self
    {
        $meta = $key !== null ? config("consoles.{$key}") : null;

        return is_array($meta) ? new self($key, $meta) : null;
    }

    /**
     * Is this a known console key?
     */
    public static function exists(string $key): bool
    {
        return is_array(config("consoles.{$key}"));
    }

    /**
     * Every console, in display order — Laravel hands the config directory over
     * alphabetically, so the `order` key is what restores the intended sequence.
     *
     * @return Collection<int, self>
     */
    public static function all(): Collection
    {
        return Collection::make((array) config('consoles'))
            ->map(fn (mixed $meta, string $key) => new self($key, (array) $meta))
            ->sortBy([['order', 'asc'], ['name', 'asc']])
            ->values();
    }

    /**
     * Console keys in display order.
     *
     * @return string[]
     */
    public static function keys(): array
    {
        return self::all()->pluck('key')->all();
    }

    /**
     * Every extension this console accepts, games and BIOS together.
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
     * Escape hatch for a config key this class does not promote to a property.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->meta, $key, $default);
    }

    /**
     * The whole config entry, with the console key folded in.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return Arr::prepend($this->meta, $this->key, 'key');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function asString(array $meta, string $key): ?string
    {
        $value = Arr::get($meta, $key);

        return $value !== null ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function asInt(array $meta, string $key): ?int
    {
        $value = Arr::get($meta, $key);

        return $value !== null ? (int) $value : null;
    }
}
