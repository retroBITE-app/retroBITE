<?php

declare(strict_types=1);

namespace App\Support\LaunchBox;

use Illuminate\Support\Str;

/**
 * A title reduced to what two spellings of the same game have in common.
 *
 * The LaunchBox dump carries no ScreenScraper or RetroAchievements id, so a
 * game is found there by name and platform alone, and the two databases
 * spell names differently: "Legend Of Zelda, The - A Link To The Past" here,
 * "The Legend of Zelda: A Link to the Past" there; "Final Fantasy 6" against
 * "Final Fantasy VI". Both sides go through key() and are compared as equals.
 *
 * Spacing, a possessive's apostrophe, "and" against "&" or "/", and a
 * Japanese long vowel written out or not are spelling; words are not.
 * Deliberately no fuzzier than that. A near miss gives a game somebody
 * else's rating, which is worse than none — the score falls back on
 * RetroAchievements and the platform's average, and says so.
 */
final class LaunchBoxTitle
{
    /**
     * Roman numerals as sequel numbers. Not X: Mega Man X is not Mega Man 10,
     * and both are on the shelf. Not I either, which is as often the pronoun.
     */
    private const NUMERALS = [
        'ii' => '2', 'iii' => '3', 'iv' => '4', 'v' => '5', 'vi' => '6', 'vii' => '7',
        'viii' => '8', 'ix' => '9', 'xi' => '11', 'xii' => '12', 'xiii' => '13',
    ];

    /** Articles a library title moves to the end ("Addams Family, The") and one database drops. */
    private const ARTICLES = 'the|a|an|le|la|les|el|los|las|der|die|das';

    /**
     * The key of a title's main part, before its subtitle — "Ehrgeiz" for
     * "Ehrgeiz: God Bless the Ring" — or null when it has none.
     */
    public static function mainKey(string $title): ?string
    {
        if (! str_contains($title, ':')) {
            return null;
        }

        $key = self::key(Str::before($title, ':'));

        return $key !== '' && $key !== self::key($title) ? $key : null;
    }

    public static function key(string $title): string
    {
        $key = Str::lower(Str::ascii($title));

        // Tags, which name a dump rather than a game: (USA), [!], (Rev 1).
        $key = (string) preg_replace('/\s*[(\[][^)\]]*[)\]]/', '', $key);

        // An article wherever a comma moved it, then one at the very start,
        // so "Smurfs, The" and "The Smurfs" meet at "smurfs".
        $key = (string) preg_replace('/\s*,\s*(?:'.self::ARTICLES.')\b/', '', $key);
        $key = (string) preg_replace('/^(?:'.self::ARTICLES.')\s+/', '', $key);

        $key = str_replace('&', ' and ', $key);
        $key = trim((string) preg_replace('/[^a-z0-9]+/', ' ', $key));

        if ($key === '') {
            return '';
        }

        $words = array_map(
            fn (string $word) => self::NUMERALS[$word] ?? $word,
            // "And" goes with "&", which a slash also stands for:
            // "Battletoads / Double Dragon", "Lethal Enforcers I & II".
            array_values(array_filter(explode(' ', $key), fn (string $word) => $word !== 'and')),
        );

        // Run together, so a space or a hyphen one database has and the other
        // does not stops mattering: "Water World" and "Waterworld", "Road
        // Rash 3-D" and "3D", "Connor's" and "Connors". Two numbers stay
        // apart, so "Game 2 3" is not "Game 23".
        $joined = '';

        foreach ($words as $index => $word) {
            $joined .= $index > 0 && ctype_digit($word) && ctype_digit($words[$index - 1]) ? ' '.$word : $word;
        }

        // A Japanese long vowel, written out or not: "Monshou" and "Monshô"
        // (which ascii() makes "Monsho"), "Juuouki" and "Juu Ou Ki".
        return str_replace(['ou', 'uu'], ['o', 'u'], $joined);
    }
}
