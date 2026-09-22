<?php

use App\Support\RatingBand;

/**
 * The colour a mark is shown in, on the shelf and on the game page alike.
 *
 * Boundaries rather than midpoints: a band is only ever got wrong at its edge,
 * and these edges are the same round numbers the shelf filter offers.
 */
it('descends through the palette as the rating falls', function (int $rating, string $token) {
    expect(RatingBand::color($rating))->toBe("var({$token})");
})->with([
    'top of the scale' => [100, '--color-accent'],
    'floor of the gold band' => [80, '--color-accent'],
    'just under gold' => [79, '--color-accent-muted'],
    'floor of the drab band' => [60, '--color-accent-muted'],
    'just under drab' => [59, '--color-warn'],
    'floor of the orange band' => [40, '--color-warn'],
    'just under orange' => [39, '--color-danger'],
    'bottom of the scale' => [0, '--color-danger'],
]);

it('reads every band against one ink', function () {
    // Four light colours, so the near-black the palette already uses on the
    // accent covers all of them and the badge needs no second decision.
    expect(RatingBand::ink())->toBe('var(--color-accent-foreground)');
});
