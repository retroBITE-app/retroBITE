<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Models\Media;

/**
 * Recalbox: games under roms/{system}/, described in roms/{system}/gamelist.xml.
 *
 * Recalbox's EmulationStation shows the picture in <image>, and its themes
 * are drawn around a mix — which is why the provider has one in Recalbox's own
 * layouts — so <image> is the mix, else the box. <thumbnail> Recalbox reads
 * but does not show; the screenshot goes there for the themes that do. It
 * finds artwork by the paths the list gives, so the folders under media/ are
 * named for what is in them.
 */
final class RecalboxTarget extends GamelistTarget
{
    /**
     * Where Recalbox names a system otherwise than we do.
     *
     * @var array<string, string>
     */
    private const SYSTEMS = [
        'amiga500' => 'amiga600',
        'c20' => 'vic20',
        'gameandwatch' => 'gw',
        'gc' => 'gamecube',
        'megacd' => 'segacd',
        'n64dd' => '64dd',
        'odyssey2' => 'o2em',
    ];

    /** @var array<string, array{folder: string, tag: string, types: list<string>}> */
    private const ARTWORK = [
        'image' => ['folder' => 'images', 'tag' => 'image', 'types' => ['mixrbv2', 'mixrbv1', 'box-2D', 'box-3D']],
        'thumbnail' => ['folder' => 'screenshots', 'tag' => 'thumbnail', 'types' => ['ss', 'sstitle']],
        'marquee' => ['folder' => 'wheels', 'tag' => 'marquee', 'types' => ['wheel', 'wheel-hd', 'wheel-carbon', 'wheel-steel', 'screenmarqueesmall', 'screenmarquee']],
        'video' => ['folder' => 'videos', 'tag' => 'video', 'types' => ['video-normalized', 'video']],
    ];

    public function key(): string
    {
        return 'recalbox';
    }

    public function label(): string
    {
        return 'Recalbox';
    }

    protected function romsFolder(): string
    {
        return 'roms';
    }

    protected function systems(): array
    {
        return self::SYSTEMS;
    }

    protected function artwork(): array
    {
        return self::ARTWORK;
    }

    /** media/{what it is}/{name of the file the list points at}.{extension} */
    protected function artworkPath(string $system, string $slot, string $primary, Media $media): string
    {
        return 'roms/'.$system.'/media/'.self::ARTWORK[$slot]['folder'].'/'.$this->stem($primary).'.'.$media->extension;
    }
}
