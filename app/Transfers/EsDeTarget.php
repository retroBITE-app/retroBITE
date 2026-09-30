<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Models\Media;

/**
 * ES-DE: games under roms/{system}/, their game list in
 * roms/gamelists/{system}/gamelist.xml and their artwork in
 * roms/downloaded_media/{system}/{kind}/ — the ROM directory holding ES-DE's
 * game lists and media beside the systems, as ES-DE for Android is set up on a
 * handheld's card.
 *
 * This is ES-DE for Android's layout, and it is labelled so. EmulationStation
 * elsewhere has no downloaded_media: Batocera's keeps images/ and videos/ in
 * each system folder, named in the game list, which is BatoceraTarget.
 *
 * ES-DE ignores the artwork tags in a game list and a gamelist.xml inside the
 * ROM folder: it finds artwork by name alone, the ROM's path inside the system
 * folder without its extension, folders and all. So the list here carries no
 * artwork, and the artwork no suffix.
 */
final class EsDeTarget extends GamelistTarget
{
    /**
     * Where ES-DE's es_systems.xml names a system otherwise than we do.
     *
     * @var array<string, string>
     */
    private const SYSTEMS = [
        '3ds' => 'n3ds',
        'amiga500' => 'amiga',
        'amigacdtv' => 'cdtv',
        'astrocade' => 'astrocde',
        'c20' => 'vic20',
        'cdi' => 'cdimono1',
        'cplus4' => 'plus4',
        'jaguar' => 'atarijaguar',
        'jaguarcd' => 'atarijaguarcd',
        'lynx' => 'atarilynx',
        'msx2plus' => 'msx2',
        'oricatmos' => 'oric',
        'sg1000' => 'sg-1000',
        'thomson' => 'moto',
        'trs80' => 'trs-80',
        'wswan' => 'wonderswan',
        'wswanc' => 'wonderswancolor',
    ];

    /**
     * ES-DE's media folders, by the name it gives each.
     *
     * @var array<string, array{types: list<string>}>
     */
    private const ARTWORK = [
        'covers' => ['types' => ['box-2D', 'box-3D']],
        '3dboxes' => ['types' => ['box-3D']],
        'backcovers' => ['types' => ['box-2D-back']],
        'physicalmedia' => ['types' => ['support-2D']],
        'screenshots' => ['types' => ['ss']],
        'titlescreens' => ['types' => ['sstitle']],
        'marquees' => ['types' => ['wheel', 'wheel-hd', 'wheel-carbon', 'wheel-steel', 'screenmarqueesmall', 'screenmarquee']],
        'fanart' => ['types' => ['fanart']],
        'miximages' => ['types' => ['mixrbv2', 'mixrbv1']],
        'videos' => ['types' => ['video-normalized', 'video']],
        'manuals' => ['types' => ['manuel']],
    ];

    public function key(): string
    {
        return 'es-de';
    }

    public function label(): string
    {
        return 'ES-DE (Android)';
    }

    public function hint(): string
    {
        return __('ES-DE finds this when roms/ on the drive is its ROM directory, with its game lists and media in roms/gamelists/ and roms/downloaded_media/.');
    }

    public function gamelistFor(string $console): string
    {
        return 'roms/gamelists/'.$this->system($console).'/gamelist.xml';
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

    /** roms/downloaded_media/{system}/{kind}/{path of the file the list points at, without extension}.{extension} */
    protected function artworkPath(string $system, string $slot, string $primary, Media $media): string
    {
        return 'roms/downloaded_media/'.$system.'/'.$slot.'/'.$this->stem($primary, folders: true).'.'.$media->extension;
    }
}
