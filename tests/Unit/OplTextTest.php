<?php

use App\Support\OplText;

/**
 * Open PS2 Loader draws one line of plain ASCII in a bitmap font, and shows a
 * fixed number of characters of a synopsis.
 *
 * Every rule here exists because a file on a working drive follows it, so the
 * assertions are against what those files carry rather than against what would
 * be nicer to read.
 */
it('folds anything unicode down to what OPL has a glyph for', function () {
    expect((new OplText)->oneLine('Pokémon Colosseum'))->toBe('Pokemon Colosseum');
});

it('flattens a wrapped synopsis onto one line', function () {
    expect((new OplText)->oneLine("Two\nlines   and\tsome  spacing "))
        ->toBe('Two lines and some spacing');
});

it('leaves a synopsis that already fits exactly as it is', function () {
    $text = 'Short enough.';

    expect((new OplText)->summarise($text))->toBe($text);
});

it('cuts a long synopsis on a word boundary', function () {
    $summary = (new OplText)->summarise(str_repeat('word ', 200));

    expect($summary)->toEndWith('...')
        // The limit plus the ellipsis, never more.
        ->and(mb_strlen($summary))->toBeLessThanOrEqual(303)
        ->and($summary)->not->toContain('wor...')
        // Three ASCII dots, because OPL cannot draw the other one.
        ->and($summary)->not->toContain('…');
});

it('takes the limit it is given rather than one baked in', function () {
    $summary = (new OplText(20))->summarise('One two three four five six seven eight');

    expect(mb_strlen($summary))->toBeLessThanOrEqual(23)
        ->and($summary)->toStartWith('One two');
});

it('leaves no dangling punctuation where it cut', function () {
    // rtrim of " \t.,;:!?-" before the ellipsis, so a cut after a comma does
    // not come out as "something, ...".
    $summary = (new OplText(12))->summarise('Hello there, world and beyond');

    expect($summary)->not->toContain(', ...')
        ->and($summary)->toEndWith('...');
});
