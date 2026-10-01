<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which region a file is, as the provider's region codes.
 *
 * The provider knows best, and says so per dump in a jeuInfos answer. A file
 * it has not been asked about still names its region the way No-Intro and
 * Redump write it — "Aerostar (USA, Europe).zip" — and the PS2 toolbox reads
 * one off the disc's serial, in English. Both are turned into the same codes
 * here, so a region stored on a file means one thing whoever wrote it.
 */
final class RomRegions
{
    /** What region names in a filename mean, as the provider's region codes. */
    private const NAMES = [
        'world' => 'wor',
        'europe' => 'eu',
        'usa' => 'us',
        'japan' => 'jp',
        'australia' => 'au',
        'brazil' => 'br',
        'canada' => 'ca',
        'china' => 'cn',
        'france' => 'fr',
        'germany' => 'de',
        'italy' => 'it',
        'korea' => 'kr',
        'netherlands' => 'nl',
        'spain' => 'sp',
        'sweden' => 'se',
        'uk' => 'uk',
        'asia' => 'asi',
    ];

    /** GoodTools' letters: (U), (E), (J), (W). */
    private const LETTERS = [
        'w' => 'wor',
        'e' => 'eu',
        'u' => 'us',
        'j' => 'jp',
    ];

    /**
     * The regions a filename's round-bracket tags name, in the order written;
     * none for a name without one.
     *
     * @return list<string>
     */
    public static function fromFilename(string $filename): array
    {
        preg_match_all('/\(([^)]*)\)/', pathinfo($filename, PATHINFO_FILENAME), $matches);

        $codes = [];

        foreach ($matches[1] as $tag) {
            $codes = [...$codes, ...self::fromTag(trim($tag))];
        }

        return array_values(array_unique($codes));
    }

    /**
     * The codes one tag names — "USA, Europe", or GoodTools' "U" — or none
     * when it is not a region tag at all.
     *
     * @return list<string>
     */
    public static function fromTag(string $tag): array
    {
        $codes = [];

        foreach (preg_split('/\s*,\s*/', strtolower($tag)) ?: [] as $word) {
            $code = self::NAMES[$word] ?? self::LETTERS[$word] ?? null;

            if ($code === null) {
                return [];
            }

            $codes[] = $code;
        }

        return $codes;
    }

    /** One English name — "Europe", as the PS2 toolbox writes it — as a code, or null for none it knows. */
    public static function fromName(?string $name): ?string
    {
        return self::NAMES[strtolower(trim((string) $name))] ?? null;
    }
}
