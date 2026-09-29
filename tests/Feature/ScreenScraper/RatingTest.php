<?php

use App\Services\ScreenScraperService;
use App\Support\Console;
use Illuminate\Support\Facades\Http;

/**
 * ScreenScraper's `note` is a mark out of twenty, contributed by their users.
 * The library sorts and filters on it out of a hundred, and the field arrives
 * as text in more shapes than its documentation admits.
 *
 * One case per test rather than a chain of them: Http::fake() appends stubs
 * instead of replacing them, so a second fake inside one test never answers.
 */
function ssRating(mixed $note): ?int
{
    $jeu = [
        'id' => '19256',
        'noms' => [['region' => 'ss', 'text' => 'Final Fantasy IX']],
    ];

    // false stands for an answer with no note block at all, which is not the
    // same as a note that is empty.
    if ($note !== false) {
        $jeu['note'] = ['text' => $note];
    }

    Http::fake(['*' => Http::response(['response' => ['jeu' => $jeu]], 200)]);

    $payload = app(ScreenScraperService::class)
        ->lookup(new Console('psx'), ['romnom' => 'x.bin', 'romtaille' => 1]);

    return $payload['rating'] ?? null;
}

it('puts the provider mark out of twenty onto a hundred', function (string $note, int $expected) {
    expect(ssRating($note))->toBe($expected);
})->with([
    'a usual mark' => ['17', 85],
    'top of the scale' => ['20', 100],
    // Rounded, not truncated: 87 would read as the mark below.
    'half a mark' => ['17.5', 88],
    // The API is French and has been seen to answer with one.
    'a comma decimal' => ['17,5', 88],
    // Clamped rather than believed. The field is contributed.
    'past the top of the scale' => ['25', 100],
]);

it('reads zero as no rating rather than as the mark zero', function () {
    // Zero is what the provider sends for a game nobody has voted on. Stored
    // as a rating it would sort below every game the provider knows nothing
    // about at all, which is the opposite of the truth.
    expect(ssRating('0'))->toBeNull();
});

it('leaves the rating null when the note is not a number', function (mixed $note) {
    expect(ssRating($note))->toBeNull();
})->with([
    'blank' => ['   '],
    'words' => ['n/a'],
    'null' => [null],
    'no note block at all' => [false],
]);
