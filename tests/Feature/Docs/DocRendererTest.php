<?php

use App\Resources\DocResource;
use App\Services\DocRenderer;
use App\Support\DocPath;

/**
 * The preview prints this HTML unescaped, so what the renderer refuses to emit
 * is as much the subject here as what it emits.
 */
function renderDoc(string $body, string $path = 'ps2/laser.md'): string
{
    $root = sys_get_temp_dir().'/docs-render-'.bin2hex(random_bytes(6));

    mkdir($root.'/ps2/media', 0o755, true);

    try {
        return (new DocRenderer(new DocPath($root)))
            ->render(DocResource::make(['path' => $path, 'body' => $body]));
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
}

test('embeds a youtube video', function () {
    expect(renderDoc('@video[https://youtu.be/ygdtkkCFxkE]'))
        ->toContain('https://www.youtube-nocookie.com/embed/ygdtkkCFxkE')
        ->toContain('</iframe>');
});

test('embeds a youtube watch url', function () {
    expect(renderDoc('@video[https://www.youtube.com/watch?v=ygdtkkCFxkE]'))
        ->toContain('/embed/ygdtkkCFxkE');
});

test('leaves a non youtube video as plain text', function () {
    expect(renderDoc('@video[https://evil.example.com/watch?v=ygdtkkCFxkE]'))
        ->not->toContain('<iframe')
        ->toContain('@video[https://evil.example.com/watch?v=ygdtkkCFxkE]');
});

test('leaves a malformed video id as plain text', function () {
    expect(renderDoc('@video[https://youtu.be/../../etc/passwd]'))
        ->not->toContain('<iframe');
});

test('lifts a lone video out of its paragraph', function () {
    expect(renderDoc('@video[https://youtu.be/ygdtkkCFxkE]'))
        ->not->toContain('<p><div');
});

test('wraps a lone image in a captioned figure', function () {
    expect(renderDoc('![Pot location](media/pot.jpg)'))
        ->toContain('<figure>')
        ->toContain('<figcaption>Pot location</figcaption>');
});

test('leaves an inline image inside its paragraph', function () {
    expect(renderDoc('Text ![x](media/pot.jpg) more text'))
        ->toContain('<p>')
        ->not->toContain('<figure>');
});

test('points an attachment at the authenticated media route', function () {
    expect(renderDoc('![Pot](media/pot.jpg)'))
        ->toContain(route('docs.media', ['path' => 'ps2/media/pot.jpg']));
});

test('refuses to link an attachment that escapes the docs root', function () {
    expect(renderDoc('![Escape](media/../../etc/passwd.jpg)'))
        ->toContain('src=""');
});

test('leaves an absolute image url alone', function () {
    expect(renderDoc('![Remote](https://example.com/a.png)'))
        ->toContain('https://example.com/a.png');
});

test('strips raw html', function () {
    expect(renderDoc('<script>alert(1)</script>'))->not->toContain('<script');
});

test('refuses an unsafe link scheme', function () {
    expect(renderDoc('[Bad](javascript:alert(1))'))->not->toContain('javascript:');
});

test('renders a github flavoured table', function () {
    $table = "| A | B |\n| - | - |\n| 1 | 2 |";

    expect(renderDoc($table))->toContain('<table>')->toContain('<th>A</th>');
});

test('every link opens in a new tab', function () {
    expect(renderDoc('[Sony manual](https://archive.org/details/ps2-service-manuals)'))
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer"');
});

test('a refused link scheme is not given a target', function () {
    expect(renderDoc('[Bad](javascript:alert(1))'))->not->toContain('javascript:');
});

/**
 * Two images on consecutive lines are one paragraph joined by a softbreak,
 * which is exactly what inserting media twice at the cursor produces. Both
 * must still become captioned figures.
 */
test('adjacent images both become figures', function () {
    $html = renderDoc("![first](media/a.jpg)\n![second](media/b.jpg)");

    expect(substr_count($html, '<figure>'))->toBe(2)
        ->and($html)->toContain('<figcaption>first</figcaption>')
        ->and($html)->toContain('<figcaption>second</figcaption>')
        ->and($html)->not->toContain('<p><figure>');
});

test('an image next to a video in one paragraph lifts both', function () {
    $html = renderDoc("![shot](media/a.jpg)\n@video[https://youtu.be/ygdtkkCFxkE]");

    expect($html)->toContain('<figure>')
        ->toContain('/embed/ygdtkkCFxkE')
        ->not->toContain('<p><figure>');
});

test('an image with text beside it stays in the paragraph', function () {
    expect(renderDoc("Look ![shot](media/a.jpg)\n![other](media/b.jpg)"))
        ->toContain('<p>')
        ->not->toContain('<figure>');
});
