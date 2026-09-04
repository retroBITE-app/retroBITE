<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * A config-backed region entry, mirroring App\Support\Console.
 *
 * Regions are user-editable at runtime, so they cannot be a PHP enum. This
 * exists so an unmatched ROM yields null rather than the invented key
 * 'unknown', which config() could never resolve and which shipped a null
 * regionMeta to the frontend for every such game.
 */
final class Region
{
    public readonly string $key;
    public readonly string $name;
    public readonly string $icon;

    /** @var string[] */
    public readonly array $codes;

    private function __construct(string $key, array $meta)
    {
        $this->key   = $key;
        $this->name  = (string) Arr::get($meta, 'name', $key);
        $this->icon  = (string) Arr::get($meta, 'icon', '');
        $this->codes = (array)  Arr::get($meta, 'codes', []);
    }

    /**
     * Build a Region, or return null if the key is unknown or absent.
     */
    public static function tryFrom(?string $key): ?self
    {
        if ($key === null || $key === '') {
            return null;
        }

        $meta = config("regions.{$key}");

        return is_array($meta) ? new self($key, $meta) : null;
    }

    /**
     * The region a ROM filename belongs to, or null when no tag matches.
     */
    public static function fromFilename(string $filename): ?self
    {
        return self::tryFrom(RomFilename::resolveRegionKey($filename));
    }

    /**
     * Payload shape the frontend reads as `region_meta`.
     *
     * @return array{key: string, name: string, icon: string, codes: string[]}
     */
    public function toArray(): array
    {
        return [
            'key'   => $this->key,
            'name'  => $this->name,
            'icon'  => $this->icon,
            'codes' => $this->codes,
        ];
    }
}
