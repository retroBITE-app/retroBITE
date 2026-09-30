<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Models\Media;

/**
 * RetroPie: games under roms/{system}/, described in roms/{system}/gamelist.xml,
 * which RetroPie's EmulationStation reads before its own in
 * ~/.emulationstation/gamelists.
 *
 * Its views show <image> large and <marquee> above it, so <image> is the box;
 * <thumbnail>, for the grid, is the screenshot. The files are named as
 * EmulationStation's own scraper names them, {name}-image and so on, in
 * images/ beside the games, and a video in videos/.
 */
final class RetroPieTarget extends GamelistTarget
{
    /**
     * Where RetroPie's platforms.cfg names a system otherwise than we do.
     * RetroPie folds several of ours into one: every MSX, every Amiga.
     *
     * @var array<string, string>
     */
    private const SYSTEMS = [
        'amiga1200' => 'amiga',
        'amiga500' => 'amiga',
        'amigacd32' => 'amiga',
        'colecovision' => 'coleco',
        'dos' => 'pc',
        'fbneo' => 'fba',
        'jaguar' => 'atarijaguar',
        'lynx' => 'atarilynx',
        'megacd' => 'segacd',
        'msx1' => 'msx',
        'msx2' => 'msx',
        'msx2plus' => 'msx',
        'msxturbor' => 'msx',
        'odyssey2' => 'videopac',
        'oricatmos' => 'oric',
        'pcenginecd' => 'pcengine',
        'sg1000' => 'sg-1000',
        'supergrafx' => 'pcengine',
        'thomson' => 'moto',
        'trs80' => 'trs-80',
        'wswan' => 'wonderswan',
        'wswanc' => 'wonderswancolor',
    ];

    /** @var array<string, array{suffix: string, tag: string, types: list<string>}> */
    private const ARTWORK = [
        'image' => ['suffix' => 'image', 'tag' => 'image', 'types' => ['box-2D', 'box-3D']],
        'thumbnail' => ['suffix' => 'thumb', 'tag' => 'thumbnail', 'types' => ['ss', 'sstitle']],
        'marquee' => ['suffix' => 'marquee', 'tag' => 'marquee', 'types' => ['wheel', 'wheel-hd', 'wheel-carbon', 'wheel-steel', 'screenmarqueesmall', 'screenmarquee']],
        'video' => ['suffix' => 'video', 'tag' => 'video', 'types' => ['video-normalized', 'video']],
    ];

    public function key(): string
    {
        return 'retropie';
    }

    public function label(): string
    {
        return 'RetroPie';
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

    /** images/{name of the file the list points at}-{what it is}.{extension}; a video in videos/. */
    protected function artworkPath(string $system, string $slot, string $primary, Media $media): string
    {
        $folder = $slot === 'video' ? 'videos' : 'images';

        return 'roms/'.$system.'/'.$folder.'/'.$this->stem($primary).'-'.self::ARTWORK[$slot]['suffix'].'.'.$media->extension;
    }
}
