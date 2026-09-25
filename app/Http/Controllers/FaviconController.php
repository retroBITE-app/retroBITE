<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ColorScheme;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The logo mark as an SVG favicon, in one colour scheme's colours.
 *
 * A favicon is fetched as a file, so it cannot read the page's CSS the way the
 * inline logos do; this writes the scheme's two logo fills into the SVG as a
 * <style> instead. The URL names the scheme, so each answer never changes and
 * is cached for good — switching scheme changes the URL, not the file behind it.
 */
class FaviconController extends Controller
{
    public function __invoke(ColorScheme $scheme): Response
    {
        $colors = $scheme->logoColors();

        $style = sprintf(
            '<style>.logo-bright{fill:%s}.logo-shade{fill:%s}</style>',
            $colors['bright'],
            $colors['shade'],
        );

        $svg = File::get(resource_path('svg/mark.svg'));

        // After the root element's opening tag, whatever attributes it carries.
        $svg = Str::replaceMatches('~<svg\b[^>]*>~', '$0'.$style, $svg, 1);

        return new Response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
