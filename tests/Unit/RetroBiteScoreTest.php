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

it('lets popularity move a rated game six points at most, either way', function () {
    // Shaq Fu's ratings with the most famous numbers there are: it has the
    // votes because people know how bad it is.
    $infamous = RetroBiteScore::compute(1.98, 74, 3.6, null);
    $famous = RetroBiteScore::compute(1.98, 74, 3.6, 1_000_000, 1.0, 100.0, 1_000_000);

    // A fine game hardly anyone has heard of, already pulled to the middle
    // for its few votes.
    $fine = RetroBiteScore::compute(4.5, 5, 3.6, null);
    $obscure = RetroBiteScore::compute(4.5, 5, 3.6, 120, 0.0, 1.0, 5_000);

    expect($famous - $infamous)->toBeLessThanOrEqual(6)
        ->and($famous)->toBeGreaterThan($infamous)
        ->and($fine - $obscure)->toBeLessThanOrEqual(6);
});

it('reads popularity from whichever sources a game has, a scrape count for half', function () {
    [$votesOnly, $sure] = RetroBiteScore::popularity(90.0, null);
    [$both] = RetroBiteScore::popularity(90.0, null, null, 5_000);
    [$scrapesOnly, $unsure] = RetroBiteScore::popularity(null, null, null, 500_000);

    // A scrape count at the floor pulls a 90 down by a third, being worth
    // half of the votes: (90 + 0.5 × 0) / 1.5.
    expect($votesOnly)->toEqual(90.0)
        ->and($sure)->toEqual(1.0)
        ->and($both)->toEqualWithDelta(60.0, 0.01)
        // Alone, it is the whole of the popularity but half as sure.
        ->and($scrapesOnly)->toEqual(100.0)
        ->and($unsure)->toEqual(0.5);
});

it('scores a game nothing rates but people play, as popularity allows', function () {
    // Half as sure as a vote count: (0.7 × 62.5 + 0.15 × 100) / 0.85.
    expect(RetroBiteScore::compute(null, 0, 3.5, null, null, null, 500_000))->toBe(69)
        ->and(RetroBiteScore::compute(null, 0, 3.5, null, null, null, null))->toBeNull();
});

it('counts a set few have played for little', function () {
    $base = RetroBiteScore::compute(4.0, 500, 3.5, null);

    expect(RetroBiteScore::compute(4.0, 500, 3.5, 10, 1.0))->toBeLessThanOrEqual($base + 1);
});

it('keeps a game only RetroAchievements knows near the middle', function () {
    $score = RetroBiteScore::compute(null, 0, 3.5, 1_000_000, 1.0);

    // Seven tenths stay at the platform's average (62.5); the rest is the
    // set at its best. Popularity alone never makes a game great.
    expect($score)->toBe(74);
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
    'one word or two' => ['Water World', 'Waterworld'],
    'a hyphen' => ['Road Rash 3-d', 'Road Rash 3D'],
    'a possessive' => ['Jimmy Connor\'s Tennis', 'Jimmy Connors Tennis'],
    'a slash for and' => ['Battletoads And Double Dragon', 'Battletoads / Double Dragon'],
    'numerals and an ampersand' => ['Dragon Quest 2 And 3', 'Dragon Quest II & III'],
    'a long vowel' => ['Fire Emblem - Monshô No Nazo', 'Fire Emblem: Monshou no Nazo'],
    'a long vowel apart' => ['Juu Ou Ki', 'Juuouki'],
]);

it('keeps games that only look alike apart', function (string $one, string $other) {
    expect(LaunchBoxTitle::key($one))->not->toBe(LaunchBoxTitle::key($other));
})->with([
    'X is not ten' => ['Mega Man X', 'Mega Man 10'],
    'a sequel' => ['Final Fantasy 2', 'Final Fantasy 3'],
    'two numbers' => ['Game 2 3', 'Game 23'],
    'a different word' => ['Alundra 2', 'Alundra'],
]);
