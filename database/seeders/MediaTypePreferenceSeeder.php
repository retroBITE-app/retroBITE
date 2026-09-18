<?php

namespace Database\Seeders;

use App\Models\MediaTypePreference;
use Illuminate\Database\Seeder;

class MediaTypePreferenceSeeder extends Seeder
{
    /**
     * Provider media types worth offering, and which are on to begin with.
     *
     * The list is hardcoded rather than read from mediasJeuListe.php: that
     * endpoint needs credentials and a cache, and this is a settings screen
     * whose options change about once a year. Anything missing can be added as
     * a row without touching code, because the column holds the provider's own
     * type name.
     *
     * On by default: one cover with a fallback, one logo, one backdrop and a
     * screenshot. Deliberately not every type MediaKind lists — those lists
     * say which artwork may stand in for a role when several exist, not what
     * to fetch. Enabling all of them would pull four near-identical logos per
     * game, and a plain account downloads at 128 KB/s.
     *
     * @var array<int, string>
     */
    private const TYPES = [
        // Boxes and physical media
        'box-2D', 'box-3D', 'box-2D-back', 'box-2D-side', 'box-texture',
        'support-2D', 'support-texture',
        // Screens
        'ss', 'sstitle', 'mixrbv1', 'mixrbv2',
        // Logos and marquees
        'wheel', 'wheel-hd', 'wheel-carbon', 'wheel-steel',
        'screenmarquee', 'screenmarqueesmall', 'steamgrid',
        // Backgrounds
        'fanart', 'bezel-16-9',
        // Heavy extras
        'video', 'video-normalized', 'manuel',
    ];

    /**
     * @var array<int, string>
     */
    private const ENABLED = ['box-2D', 'box-3D', 'wheel', 'fanart', 'ss'];

    public function run(): void
    {
        foreach (self::TYPES as $type) {
            MediaTypePreference::query()->firstOrCreate(
                ['media_type' => $type],
                ['enabled' => in_array($type, self::ENABLED, true)],
            );
        }
    }
}
