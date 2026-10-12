<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The retroBite score: how good a game is, out of a hundred.
 *
 * Ours, worked out from what other people measured (docs/adr/0006), in two
 * parts:
 *
 * - **Quality, seven tenths** — the LaunchBox Games Database's community
 *   rating, out of five, pulled towards the platform's average by a fixed
 *   number of imaginary votes, so a game two people gave five stars does not
 *   outrank one two thousand people gave four and a half.
 * - **Popularity, three tenths** — how many people play it, from whichever of
 *   three sources the game has, each read on its own scale and averaged by
 *   weight: how many voted on LaunchBox, against the other games of its
 *   platform; how many played its RetroAchievements set, and how far the
 *   typical one got; and how many have scraped its most popular dump from
 *   ScreenScraper, the noisiest of the three and worth half of either.
 *   Popularity is what tells a classic from a curiosity when the votes are
 *   level — never more: for a rated game it may stand at most
 *   POPULARITY_REACH from the quality, so it moves the score six points
 *   either way at most, and its share shrinks with how little there is to go on: a set
 *   ten people tried, or a scrape count alone, moves the score next to
 *   nothing.
 *
 * A game LaunchBox does not rate keeps its quality at the platform's average,
 * so popularity alone moves it only so far from the middle. A game nothing
 * knows has no score.
 *
 * ScreenScraper's note is not an ingredient. Its voters rate the entry —
 * whether the artwork is good — more than the game.
 */
final class RetroBiteScore
{
    /** Share of the score that is quality: players' ratings. */
    public const QUALITY_WEIGHT = 0.7;

    /** Share of the score that is popularity, at full confidence. */
    public const POPULARITY_WEIGHT = 0.3;

    /**
     * How far from a rated game's quality its popularity may stand, so the
     * most it can move the score is this times POPULARITY_WEIGHT. A famous
     * stinker has the votes and the downloads because it is famous, and a
     * fine game few have heard of has already been pulled towards the
     * average for its few votes; neither is popularity's to undo.
     */
    public const POPULARITY_REACH = 20;

    /** Imaginary votes at the platform's average that every rating is mixed with. */
    public const PRIOR_VOTES = 10;

    /** The mean to pull towards when a platform's own is unknown. */
    public const FALLBACK_MEAN = 3.5;

    /** How much each popularity source counts, at full confidence; together at least one is full confidence. */
    public const VOTES_SIGNAL = 1.0;

    public const RETROACHIEVEMENTS_SIGNAL = 1.0;

    public const SCRAPES_SIGNAL = 0.5;

    /** Players a set needs before RetroAchievements counts in full. */
    public const FULL_CONFIDENCE_PLAYERS = 500;

    /** Players for nothing and for everything on the popularity scale, which is logarithmic. */
    public const POPULARITY_FLOOR = 100;

    public const POPULARITY_CEILING = 100_000;

    /** Scrapes of a game's most popular dump for nothing and for everything, also logarithmic. */
    public const SCRAPES_FLOOR = 5_000;

    public const SCRAPES_CEILING = 500_000;

    /** The share of players past the typical progression step that counts as all of them. */
    public const ENGAGEMENT_CEILING = 0.25;

    /** How much of the RetroAchievements part is popularity; the rest is engagement. */
    public const POPULARITY_SHARE = 0.7;

    /**
     * @param  float|null  $rating  LaunchBox's average, out of five
     * @param  int  $votes  how many voted for that average
     * @param  float|null  $platformMean  the platform's average, out of five
     * @param  int|null  $players  RetroAchievements' distinct players of the set
     * @param  float|null  $engagement  the share of those players who unlocked the set's typical progression achievement
     * @param  float|null  $votePercentile  where the game's vote count stands among its LaunchBox platform's games, 0 to 100
     * @param  int|null  $scrapes  ScreenScraper's scrape count for the game's most popular dump
     */
    public static function compute(
        ?float $rating,
        int $votes,
        ?float $platformMean,
        ?int $players,
        ?float $engagement = null,
        ?float $votePercentile = null,
        ?int $scrapes = null,
    ): ?int {
        $rated = $rating !== null && $votes > 0;
        [$popularity, $confidence] = self::popularity($votes > 0 ? $votePercentile : null, $players, $engagement, $scrapes);

        if (! $rated && $confidence === 0.0) {
            return null;
        }

        $mean = $platformMean ?? self::FALLBACK_MEAN;
        $stars = $rated ? ($votes * $rating + self::PRIOR_VOTES * $mean) / ($votes + self::PRIOR_VOTES) : $mean;

        // One star is the floor LaunchBox lets anybody give, so it is zero here.
        $quality = self::clamp(($stars - 1) / 4 * 100);

        if ($rated) {
            $popularity = max($quality - self::POPULARITY_REACH, min($quality + self::POPULARITY_REACH, $popularity));
        }

        $weight = self::POPULARITY_WEIGHT * $confidence;

        $score = (self::QUALITY_WEIGHT * $quality + $weight * $popularity) / (self::QUALITY_WEIGHT + $weight);

        return (int) round(self::clamp($score));
    }

    /**
     * The popularity part out of a hundred, and how sure it is, 0 to 1.
     *
     * Each source the game has, averaged by how much it counts; the
     * confidence is how much they count together, never past one.
     *
     * @return array{0: float, 1: float}
     */
    public static function popularity(?float $votePercentile, ?int $players, ?float $engagement = null, ?int $scrapes = null): array
    {
        $signals = [];

        if ($votePercentile !== null) {
            $signals[] = [self::clamp($votePercentile), self::VOTES_SIGNAL];
        }

        $retro = self::retroAchievements($players, $engagement);

        if ($retro !== null) {
            $signals[] = [$retro, self::RETROACHIEVEMENTS_SIGNAL * min(1, (int) $players / self::FULL_CONFIDENCE_PLAYERS)];
        }

        if ($scrapes !== null && $scrapes > 0) {
            $signals[] = [self::logScale($scrapes, self::SCRAPES_FLOOR, self::SCRAPES_CEILING), self::SCRAPES_SIGNAL];
        }

        $weights = array_sum(array_column($signals, 1));

        if ($weights <= 0) {
            return [0.0, 0.0];
        }

        $value = array_sum(array_map(fn (array $signal): float => $signal[0] * $signal[1], $signals)) / $weights;

        return [$value, min(1.0, $weights)];
    }

    /** The RetroAchievements part out of a hundred, or null with nobody to count. */
    public static function retroAchievements(?int $players, ?float $engagement = null): ?float
    {
        if ($players === null || $players <= 0) {
            return null;
        }

        $popularity = self::logScale($players, self::POPULARITY_FLOOR, self::POPULARITY_CEILING);

        if ($engagement === null) {
            return $popularity;
        }

        $engaged = self::clamp($engagement / self::ENGAGEMENT_CEILING * 100);

        return self::POPULARITY_SHARE * $popularity + (1 - self::POPULARITY_SHARE) * $engaged;
    }

    private static function logScale(int $value, int $floor, int $ceiling): float
    {
        return self::clamp((log10($value) - log10($floor)) / (log10($ceiling) - log10($floor)) * 100);
    }

    private static function clamp(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }
}
