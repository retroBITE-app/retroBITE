<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Models\Game;
use App\Models\Media;

/**
 * Daijishō: games under roms/{system}/, with the artwork beside them in the
 * folders Daijishō's Import Preview Media asks for, one per kind. The same
 * roms/ as ES-DE's, so both can read one card: Daijishō is told where each
 * platform is anyway.
 *
 * Daijishō reads no artwork paths: a platform's box art, title screens and
 * screenshots are imported by hand, a folder at a time, and matched to the
 * games by name — the ROM's file name without its extension. The folders are
 * named as Skraper names them, which is what guides to Daijishō expect. Its
 * paths are chosen per platform in the app, so the system folders keep our
 * names.
 *
 * The gamelist.xml beside them is for Daijishō's import of names,
 * descriptions and genres, which is all it takes from one.
 */
final class DaijishouTarget extends GamelistTarget
{
    /**
     * Import Preview Media's three folders.
     *
     * `label` is what Import Preview Media calls each.
     *
     * @var array<string, array{types: list<string>, label: string}>
     */
    private const ARTWORK = [
        'box2dfront' => ['types' => ['box-2D', 'box-3D'], 'label' => 'Box Art'],
        'screenshottitle' => ['types' => ['sstitle'], 'label' => 'Title'],
        'screenshot' => ['types' => ['ss'], 'label' => 'Screenshot'],
    ];

    public function key(): string
    {
        return 'daijishou';
    }

    public function label(): string
    {
        return 'Daijishō';
    }

    public function hint(): string
    {
        return __('Daijishō does not look for artwork itself. For each platform, add its folder under roms/, then under Import Preview Media choose media/box2dfront as Box Art, media/screenshottitle as Title and media/screenshot as Screenshot, and import gamelist.xml for the names and descriptions.');
    }

    protected function romsFolder(): string
    {
        return 'roms';
    }

    protected function artwork(): array
    {
        return self::ARTWORK;
    }

    /** media/{Skraper's folder}/{name of the file the list points at}.{extension} */
    protected function artworkPath(string $system, string $slot, string $primary, Media $media): string
    {
        return 'roms/'.$system.'/media/'.$slot.'/'.$this->stem($primary).'.'.$media->extension;
    }

    protected function fields(Game $game, ?string $path, array $artwork): array
    {
        return array_intersect_key(parent::fields($game, $path, $artwork), array_flip(['path', 'name', 'desc', 'genre']));
    }
}
