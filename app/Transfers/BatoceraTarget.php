<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Models\Media;

/**
 * Batocera: games under roms/{system}/, described in roms/{system}/gamelist.xml.
 *
 * The system folder is the console's own key — ours were named after
 * Batocera's systems to begin with — but for the few in {@see SYSTEMS}. A
 * game's files keep the arrangement they have in the library, relative to the
 * console's folder, so a multi-disc set arrives as its playlist, cuesheets and
 * tracks, and Batocera launches the playlist. Its artwork goes to images/
 * beside it, one file per kind Batocera knows ({@see ARTWORK}), named after the
 * file the game list points at, as Batocera's own scraper names them; a video
 * goes to videos/.
 */
final class BatoceraTarget extends GamelistTarget
{
    /**
     * Where Batocera's es_systems.yml names a system otherwise than we do.
     *
     * @var array<string, string>
     */
    private const SYSTEMS = [
        'gc' => 'gamecube',
        'msx2plus' => 'msx2+',
    ];

    /**
     * The artwork Batocera shows, by gamelist.xml tag: the suffix its file
     * takes in images/, and the provider media types that can fill it, most
     * preferred first. Only artwork already downloaded goes; a type nobody
     * fetched is simply left out, tag and all.
     *
     * `label` is what Batocera's game artwork setting calls the tag.
     *
     * @var array<string, array{suffix: string, label: string, types: list<string>}>
     */
    public const ARTWORK = [
        // The tags are named backwards from what they hold, as EmulationStation
        // has them: <thumbnail> is the box, <image> the screenshot. The files
        // are named for the tag, as Batocera's own scraper names them: with
        // <image> empty, Batocera looks for {name}-image.png itself, and a box
        // under that name would show twice in a theme that also shows the box.
        'thumbnail' => ['suffix' => 'thumb', 'label' => 'boxart', 'types' => ['box-2D', 'box-3D']],
        // Never a mix: themes such as Iconic build their own mix out of
        // <image>, <thumbnail> and <marquee>, and a mix in <image> shows the
        // box and the logo twice. A title screen is still a frame of the game.
        'image' => ['suffix' => 'image', 'label' => 'image', 'types' => ['ss', 'sstitle']],
        'marquee' => ['suffix' => 'marquee', 'label' => 'logo', 'types' => ['wheel', 'wheel-hd', 'wheel-carbon', 'wheel-steel', 'screenmarqueesmall', 'screenmarquee']],
        'fanart' => ['suffix' => 'fanart', 'label' => 'fanart', 'types' => ['fanart']],
        'boxback' => ['suffix' => 'boxback', 'label' => 'box back', 'types' => ['box-2D-back']],
        'cartridge' => ['suffix' => 'cartridge', 'label' => 'cartridge', 'types' => ['support-2D']],
        'titleshot' => ['suffix' => 'titleshot', 'label' => 'title shot', 'types' => ['sstitle']],
        'mix' => ['suffix' => 'mix', 'label' => 'mix', 'types' => ['mixrbv2', 'mixrbv1']],
        'manual' => ['suffix' => 'manual', 'label' => 'manual', 'types' => ['manuel']],
    ];

    /**
     * Artwork Batocera shows that is not a picture, so not something the
     * media settings offer to switch on for it: a clip is several megabytes
     * a game.
     *
     * @var array<string, array{suffix: string, types: list<string>}>
     */
    private const EXTRAS = [
        'video' => ['suffix' => 'video', 'types' => ['video-normalized', 'video']],
    ];

    /**
     * Types whose file is named for what it is rather than for the tag it
     * fills: a title screen standing in for a missing screenshot is still a
     * title shot, and goes once for both tags. The files are there for
     * somebody to find and replace by hand, so the name has to say what is
     * in them.
     *
     * @var array<string, string>
     */
    private const SUFFIX_BY_TYPE = [
        'sstitle' => 'titleshot',
    ];

    /**
     * Where each provider media type can end up in Batocera, by its setting's
     * name — for the media settings screen.
     *
     * @return array<string, string> type => label
     */
    public static function artworkLabels(): array
    {
        $labels = [];

        foreach (self::ARTWORK as ['label' => $label, 'types' => $types]) {
            foreach ($types as $type) {
                $labels[$type][] = $label;
            }
        }

        // A title screen can be the image and the title shot at once.
        return array_map(fn (array $names): string => implode(', ', $names), $labels);
    }

    /**
     * What to fetch for a Batocera box: the preferred type of each tag the
     * game list writes, and not its fallbacks. Batocera shows one picture per
     * tag, and every type switched on is another download for every game.
     *
     * @return list<string>
     */
    public static function recommendedTypes(): array
    {
        return array_values(array_unique(array_map(
            fn (array $artwork): string => $artwork['types'][0],
            self::ARTWORK,
        )));
    }

    public function key(): string
    {
        return 'batocera';
    }

    public function label(): string
    {
        return 'Batocera';
    }

    public function root(): array
    {
        return ['roms', 'batocera/roms'];
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
        $artwork = [];

        foreach ([...self::ARTWORK, ...self::EXTRAS] as $tag => ['types' => $types]) {
            $artwork[$tag] = ['types' => $types, 'tag' => $tag];
        }

        return $artwork;
    }

    /** images/{name of the file the list points at}-{what it is}.{extension}; a video in videos/. */
    protected function artworkPath(string $system, string $slot, string $primary, Media $media): string
    {
        $suffix = self::SUFFIX_BY_TYPE[$media->screenscraper_type] ?? ([...self::ARTWORK, ...self::EXTRAS][$slot]['suffix']);
        $folder = $slot === 'video' ? 'videos' : 'images';

        return 'roms/'.$system.'/'.$folder.'/'.$this->stem($primary).'-'.$suffix.'.'.$media->extension;
    }
}
