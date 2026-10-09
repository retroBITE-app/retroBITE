<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The retroBite score: how good a game is, out of a hundred.
 *
 * Ours, worked out from two things other people measured (docs/adr/0006):
 *
 * - **What players think of it** — the LaunchBox Games Database's community
 *   rating, out of five. Three quarters of the score. Pulled towards the
 *   platform's average by a fixed number of imaginary votes, so a game two
 *   people gave five stars does not outrank one two thousand people gave
 *   four and a half.
 * - **Whether people play it** — RetroAchievements, the last quarter: how many
 *   have played the set, on a log scale, and how far the typical one of them
 *   got. Popularity is not quality, which is why it is the smaller part; it
 *   is what tells a classic from a curiosity when the votes are level. Its
 *   share shrinks with fewer players, so a set ten people tried moves the
 *   score next to nothing.
 *
 * A game LaunchBox does not rate keeps its three quarters at the platform's
 * average, so RetroAchievements alone can move it only so far from the
 * middle. A game neither source knows has no score.
 *
 * ScreenScraper's note is not an ingredient. Its voters rate the entry —
 * whether the artwork is good — more than the game.
 */
final class RetroBiteScore
{
    /** Share of the score that is players' ratings. */
    public const LAUNCHBOX_WEIGHT = 0.75;

    /** Share of the score that is RetroAchievements, at full confidence. */
    public const RETROACHIEVEMENTS_WEIGHT = 0.25;

    /** Imaginary votes at the platform's average that every rating is mixed with. */
    public const PRIOR_VOTES = 10;

    /** The mean to pull towards when a platform's own is unknown. */
    public const FALLBACK_MEAN = 3.5;

    /** Players a set needs before RetroAchievements counts in full. */
    public const FULL_CONFIDENCE_PLAYERS = 500;

    /** Players for nothing and for everything on the popularity scale, which is logarithmic. */
    public const POPULARITY_FLOOR = 100;

    public const POPULARITY_CEILING = 100_000;

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
     */
    public static function compute(
        ?float $rating,
        int $votes,
        ?float $platformMean,
        ?int $players,
        ?float $engagement = null,
    ): ?int {
        $rated = $rating !== null && $votes > 0;
        $retro = self::retroAchievements($players, $engagement);
        $retroWeight = $retro === null ? 0.0 : self::RETROACHIEVEMENTS_WEIGHT * min(1, $players / self::FULL_CONFIDENCE_PLAYERS);

        if (! $rated && $retroWeight === 0.0) {
            return null;
        }

        $mean = $platformMean ?? self::FALLBACK_MEAN;
        $stars = $rated ? ($votes * $rating + self::PRIOR_VOTES * $mean) / ($votes + self::PRIOR_VOTES) : $mean;

        // One star is the floor LaunchBox lets anybody give, so it is zero here.
        $fromRatings = self::clamp(($stars - 1) / 4 * 100);

        $score = (self::LAUNCHBOX_WEIGHT * $fromRatings + $retroWeight * (float) $retro)
            / (self::LAUNCHBOX_WEIGHT + $retroWeight);

        return (int) round(self::clamp($score));
    }

    /** The RetroAchievements part out of a hundred, or null with nobody to count. */
    public static function retroAchievements(?int $players, ?float $engagement = null): ?float
    {
        if ($players === null || $players <= 0) {
            return null;
        }

        $floor = log10(self::POPULARITY_FLOOR);
        $popularity = self::clamp((log10($players) - $floor) / (log10(self::POPULARITY_CEILING) - $floor) * 100);

        if ($engagement === null) {
            return $popularity;
        }

        $engaged = self::clamp($engagement / self::ENGAGEMENT_CEILING * 100);

        return self::POPULARITY_SHARE * $popularity + (1 - self::POPULARITY_SHARE) * $engaged;
    }

    private static function clamp(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }
}
