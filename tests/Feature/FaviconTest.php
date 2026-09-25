<?php

use App\Enums\ColorScheme;

/**
 * The favicon is a file, so it cannot read the page's CSS; the route writes
 * each scheme's logo fills into the SVG itself.
 */
it('serves the mark in the colours of the scheme it names', function () {
    $response = $this->get(route('favicon', ColorScheme::Cobalt))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml');

    expect($response->getContent())
        ->toContain('<svg')
        ->toContain('.logo-bright{fill:'.ColorScheme::Cobalt->logoColors()['bright'].'}')
        ->toContain('.logo-shade{fill:'.ColorScheme::Cobalt->logoColors()['shade'].'}');
});

it('lets a browser keep each scheme\'s icon for good', function () {
    $cacheControl = $this->get(route('favicon', ColorScheme::Default))->headers->get('Cache-Control');

    expect($cacheControl)->toContain('immutable')->toContain('max-age=31536000');
});

it('answers 404 for a scheme it does not ship', function () {
    $this->get('/favicon/neon.svg')->assertNotFound();
});
