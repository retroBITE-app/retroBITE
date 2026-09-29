<?php

use App\Enums\ColorScheme;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * The stylesheet states some colours twice on purpose: Default's palette in the
 * theme block and again in its [data-scheme] block, for the settings previews,
 * and every scheme's logo fills there and in ColorScheme, for the favicon.
 * These keep each pair equal, so a tuned hex cannot leave its copy behind.
 */

/**
 * The custom properties one block of app.css declares, by name.
 *
 * @return array<string, string>
 */
function schemeCssBlock(string $opening): array
{
    $css = (string) file_get_contents(__DIR__.'/../../resources/css/app.css');

    $body = Str::before(Str::after($css, $opening), '}');

    preg_match_all('~(--[\w-]+):\s*([^;]+);~', $body, $matches, PREG_SET_ORDER);

    return collect($matches)
        ->mapWithKeys(function (array $match): array {
            return [$match[1] => Str::lower(trim($match[2]))];
        })
        ->all();
}

it('restates the default palette exactly as @theme states it', function () {
    $theme = schemeCssBlock('@theme static {');
    $default = schemeCssBlock("[data-scheme='default'] {");

    expect($default)->not->toBeEmpty();

    foreach ($default as $name => $value) {
        expect(Arr::get($theme, $name))->toBe($value, "{$name} differs from @theme");
    }
});

it('gives the favicon the same logo fills as the stylesheet', function (ColorScheme $scheme) {
    $block = schemeCssBlock("[data-scheme='{$scheme->value}'] {");

    expect($scheme->logoColors())->toBe([
        'bright' => Arr::get($block, '--color-logo'),
        'shade' => Arr::get($block, '--color-logo-shade'),
    ]);
})->with(ColorScheme::cases());
