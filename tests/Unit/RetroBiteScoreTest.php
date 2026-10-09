<?php

use App\Support\LaunchBox\LaunchBoxTitle;
use App\Support\RetroBiteScore;

/*
 * The retroBite score's arithmetic, apart from where its inputs come from.
 * Figures are the real ones of the games named, as of October 2026.
 */

it('has no score for a game neither source knows', function () {
    expect(RetroBiteScore::compute(null, 0, 3.6, null))->toBeNull()
        ->and(RetroBiteScore::compute(4.5, 0, 3.6, null))->toBeNull()
        ->and(RetroBiteScore::compute(null, 0, 3.6, 0))->toBeNull();
});

it('scores a classic high and a stinker low', function () {
    // Super Mario World: 4.73 from 1 836 votes, 111 015 players.
    $classic = RetroBiteScore::compute(4.73, 1836, 3.6, 111015, 0.4);

    // Shaq Fu: 1.98 from 74 votes, 1 460 players.
    $stinker = RetroBiteScore::compute(1.98, 74, 3.6, 1460, 0.05);

    expect($classic)->toBeGreaterThanOrEqual(90)
        ->and($stinker)->toBeLessThanOrEqual(35);
});

it('pulls a thinly voted game towards the platform average', function () {
    $twoVotes = RetroBiteScore::compute(5.0, 2, 3.5, null);
    $manyVotes = RetroBiteScore::compute(5.0, 2000, 3.5, null);
    $average = RetroBiteScore::compute(3.5, 2000, 3.5, null);

    // Two perfect votes are worth a little more than the average and far
    // less than two thousand of them.
    expect($twoVotes)->toBeGreaterThan($average)
        ->and($twoVotes)->toBeLessThan($manyVotes - 25)
        ->and($manyVotes)->toBe(100);
});

it('lets RetroAchievements move the score by its quarter at most', function () {
    $alone = RetroBiteScore::compute(2.0, 5000, 3.5, null);
    $popular = RetroBiteScore::compute(2.0, 5000, 3.5, 1_000_000, 1.0);

    // A hundred on the RetroAchievements side, a quarter of the weight.
    expect($popular - $alone)->toBeLessThanOrEqual(25)
        ->and($popular)->toBeGreaterThan($alone);
});

it('counts a set few have played for little', function () {
    $base = RetroBiteScore::compute(4.0, 500, 3.5, null);

    expect(RetroBiteScore::compute(4.0, 500, 3.5, 10, 1.0))->toBeLessThanOrEqual($base + 1);
});

it('keeps a game only RetroAchievements knows near the middle', function () {
    $score = RetroBiteScore::compute(null, 0, 3.5, 1_000_000, 1.0);

    // Three quarters stay at the platform's average (62.5); the rest is the
    // set at its best. Popularity alone never makes a game great.
    expect($score)->toBe(72);
});

it('reads popularity on a log scale between its anchors', function () {
    expect(RetroBiteScore::retroAchievements(100))->toEqual(0.0)
        ->and(RetroBiteScore::retroAchievements(100_000))->toEqual(100.0)
        ->and(RetroBiteScore::retroAchievements(3_162))->toEqualWithDelta(50.0, 0.1)
        ->and(RetroBiteScore::retroAchievements(0))->toBeNull();
});

/*
 * Titles, reduced so that two databases' spellings of one game meet.
 */

it('reduces two spellings of one game to the same key', function (string $library, string $launchbox) {
    expect(LaunchBoxTitle::key($library))->toBe(LaunchBoxTitle::key($launchbox));
})->with([
    'moved article' => ['Legend Of Zelda, The - A Link To The Past', 'The Legend of Zelda: A Link to the Past'],
    'stray space' => ['Lion King ,The', 'The Lion King'],
    'numerals' => ['Final Fantasy 6', 'Final Fantasy VI'],
    'ampersand' => ['Sonic & Knuckles', 'Sonic and Knuckles'],
    'accents' => ['Pokémon - Red Version', 'Pokemon Red Version'],
    'dump tags' => ['Super Mario World (USA) [!]', 'Super Mario World'],
    'french article' => ['Schtroumpfs, Les', 'Les Schtroumpfs'],
]);

it('keeps games that only look alike apart', function (string $one, string $other) {
    expect(LaunchBoxTitle::key($one))->not->toBe(LaunchBoxTitle::key($other));
})->with([
    'X is not ten' => ['Mega Man X', 'Mega Man 10'],
    'a sequel' => ['Final Fantasy 2', 'Final Fantasy 3'],
]);
