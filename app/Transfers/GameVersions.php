<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Models\Game;
use App\Models\GameFile;
use App\Support\MediaRegions;

/**
 * The versions of a game on disk, and the one to send.
 *
 * A game is every region, revision and disc of one title, so it can hold
 * several copies that each work alone: Aerostar (USA, Europe), Aerostar
 * (Japan), Aerostar (USA, Europe) (Fr). A target shows one entry per game, so
 * sending all of them would leave the others on the drive as games of their
 * own, without a name or a picture. One is sent instead.
 *
 * A version is a file with no parent and everything beneath it: a ROM alone,
 * a cuesheet and its tracks, a playlist and its discs. Which one is chosen is
 * read off the names, the way No-Intro and Redump write them:
 *
 * 1. no pre-release, hack or bad dump — (Beta), (Proto), [b];
 * 2. the region highest in the library's region order, then World, Europe,
 *    the United States and Japan, then anywhere else;
 * 3. the latest revision — (Rev 1) over none, (v1.1) over (v1.0);
 * 4. the fewest other tags — (USA, Europe) over (USA, Europe) (Fr);
 * 5. and the path, so the answer never depends on the order rows came back.
 */
final class GameVersions
{
    /** Pre-release, altered and bad copies, in the tags No-Intro and GoodTools write. */
    private const UNWANTED = '/^(?:beta|proto(?:type)?|demo|sample|preview|kiosk|debug|hack|pirate|bootleg)\b/i';

    /** GoodTools' flags for a bad, hacked, fixed, pirated or trained dump. */
    private const UNWANTED_FLAG = '/^(?:b|h|f|p|t)\d*\b/i';

    /** What region names in a filename mean, as the provider's region codes. */
    private const REGIONS = [
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
        // GoodTools' letters.
        'w' => 'wor',
        'e' => 'eu',
        'u' => 'us',
        'j' => 'jp',
    ];

    /**
     * Every version of the game, each a list of its present files, root first.
     *
     * @return list<list<GameFile>>
     */
    public static function of(Game $game): array
    {
        $files = $game->files
            ->filter(fn (GameFile $file): bool => $file->isPresent())
            ->sortBy('path')
            ->keyBy('id');

        $versions = [];

        // Grouped by the top of each family, and discs with no playlist over
        // them by their name without "(Disc 2)": Final Fantasy VII (Disc 1)
        // and (Disc 2) lying loose in the folder are one version, not two.
        foreach ($files as $file) {
            $root = self::rootOf($file, $files->all());
            $versions[self::setName($root) ?? 'file:'.$root->id][] = $file;
        }

        return array_values(array_map(
            fn (array $members): array => self::rootFirst($members),
            $versions,
        ));
    }

    /**
     * The version to send, or an empty list for a game with nothing on disk.
     *
     * @return list<GameFile>
     */
    public static function preferred(Game $game): array
    {
        $versions = self::of($game);

        if (count($versions) < 2) {
            return $versions[0] ?? [];
        }

        $chain = array_values(array_filter(MediaRegions::chain(), fn (string $region): bool => $region !== 'ss'));

        usort($versions, fn (array $a, array $b): int => self::rank($a[0], $chain) <=> self::rank($b[0], $chain));

        return $versions[0];
    }

    /**
     * The top of a file's family among the files present. A file whose parent
     * is not present is its own top: a disc whose playlist has gone still
     * plays.
     *
     * @param  array<int, GameFile>  $files
     */
    private static function rootOf(GameFile $file, array $files): GameFile
    {
        $seen = [];

        while ($file->parent_id !== null && isset($files[$file->parent_id]) && ! isset($seen[$file->id])) {
            $seen[$file->id] = true;
            $file = $files[$file->parent_id];
        }

        return $file;
    }

    /**
     * The name a loose disc shares with the other discs of its set, or null
     * for a file that is not a disc.
     */
    private static function setName(GameFile $root): ?string
    {
        $name = pathinfo($root->path, PATHINFO_DIRNAME).'/'.pathinfo($root->filename, PATHINFO_FILENAME);
        $set = preg_replace('/\s*\((?:disc|disk|cd)\s*\d+(?:\s*of\s*\d+)?\)/i', '', $name);

        return $set !== null && $set !== $name ? 'set:'.$set : null;
    }

    /**
     * Families first, in path order — a set's first disc before its second.
     *
     * @param  list<GameFile>  $members
     * @return list<GameFile>
     */
    private static function rootFirst(array $members): array
    {
        usort($members, fn (GameFile $a, GameFile $b): int => [$a->parent_id !== null, $a->path] <=> [$b->parent_id !== null, $b->path]);

        return $members;
    }

    /**
     * How good a copy the version is, lowest first.
     *
     * @param  list<string>  $chain
     * @return array{int, int, int, int, string}
     */
    private static function rank(GameFile $root, array $chain): array
    {
        preg_match_all('/\(([^)]*)\)|\[([^\]]*)\]/', pathinfo($root->filename, PATHINFO_FILENAME), $matches, PREG_SET_ORDER);

        $unwanted = 0;
        $regionRank = count($chain) + 1;
        $revision = 0;
        $others = 0;

        foreach ($matches as $match) {
            $round = trim($match[1] ?? '');
            $square = trim($match[2] ?? '');

            if ($square !== '') {
                $unwanted = max($unwanted, (int) preg_match(self::UNWANTED_FLAG, $square));

                continue;
            }

            if (preg_match(self::UNWANTED, $round)) {
                $unwanted = 1;

                continue;
            }

            if (($found = self::revision($round)) !== null) {
                $revision = max($revision, $found);

                continue;
            }

            $regions = self::regions($round);

            if ($regions !== []) {
                foreach ($regions as $region) {
                    $at = array_search($region, $chain, true);
                    $regionRank = min($regionRank, $at === false ? count($chain) : $at);
                }

                continue;
            }

            $others++;
        }

        // Negated so that a later revision sorts first.
        return [$unwanted, $regionRank, -$revision, $others, $root->path];
    }

    /**
     * The provider codes a tag names, or none when it is not a region tag.
     *
     * @return list<string>
     */
    private static function regions(string $tag): array
    {
        $codes = [];

        foreach (preg_split('/\s*,\s*/', strtolower($tag)) ?: [] as $word) {
            if (! isset(self::REGIONS[$word])) {
                return [];
            }

            $codes[] = self::REGIONS[$word];
        }

        return $codes;
    }

    /** "Rev 1", "Rev A" or "v1.1" as a comparable number, or null for another tag. */
    private static function revision(string $tag): ?int
    {
        if (preg_match('/^rev\s*([0-9]+)(?:\.([0-9]+))?$/i', $tag, $parts)) {
            return (int) $parts[1] * 100 + (int) ($parts[2] ?? 0);
        }

        if (preg_match('/^rev\s*([a-z])$/i', $tag, $parts)) {
            return ord(strtolower($parts[1])) - ord('a') + 1;
        }

        if (preg_match('/^v\s*([0-9]+)(?:\.([0-9]+))?/i', $tag, $parts)) {
            return (int) $parts[1] * 100 + (int) ($parts[2] ?? 0);
        }

        return null;
    }
}
