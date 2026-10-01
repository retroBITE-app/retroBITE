<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Models\Game;
use App\Models\GameFile;
use App\Support\RomRegions;
use App\Support\TransferRegions;
use Illuminate\Support\Str;

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
 * read off the names, the way No-Intro and Redump write them, and the region
 * the file was recorded as (RomRegions):
 *
 * 1. no pre-release, hack, translation or bad dump — (Beta), (Proto), [b],
 *    or a dump the provider flags as one whatever its name says;
 * 2. the region highest in the region order — the one asked for, then the
 *    console's or the library's (TransferRegions) — then anywhere else;
 * 3. the dump most people play: the provider's pick of the dumps, then the
 *    one scraped most often (ProviderDumps), then one it does not know;
 * 4. the fewest other tags — (USA, Europe) over (USA, Europe) (Fr), and
 *    (USA) over (USA) (PtBr) (v1.0), a fan translation's own version number
 *    being no revision of the game;
 * 5. the latest revision — (Rev 1) over none, (v1.1) over (v1.0);
 * 6. and the path, so the answer never depends on the order rows came back.
 */
final class GameVersions
{
    /** Pre-release, altered and bad copies, in the tags No-Intro and GoodTools write. */
    private const UNWANTED = '/^(?:beta|proto(?:type)?|demo|sample|preview|kiosk|debug|hack|pirate|bootleg)\b/i';

    /** The provider's flags for a dump nobody sends by choice. */
    private const UNWANTED_PROVIDER_FLAGS = ['beta', 'demo', 'proto', 'hack', 'trad'];

    /** GoodTools' flags for a bad, hacked, fixed, pirated or trained dump. */
    private const UNWANTED_FLAG = '/^(?:b|h|f|p|t)\d*\b/i';

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
     * The version to send, or an empty list for a game with nothing on disk —
     * or, asked for certain formats, with no version in any of them.
     *
     * @param  list<string>  $extensions  lower case, e.g. ['iso', 'cso']; none for any
     * @param  list<string>|null  $chain  regions in the order wanted; null for the console's region order
     * @return list<GameFile>
     */
    public static function preferred(Game $game, array $extensions = [], ?array $chain = null): array
    {
        $versions = self::of($game);

        if ($extensions !== []) {
            $versions = array_values(array_filter($versions, function (array $version) use ($extensions): bool {
                return in_array(Str::lower((string) $version[0]->extension), $extensions, true);
            }));
        }

        if (count($versions) < 2) {
            return $versions[0] ?? [];
        }

        $chain ??= TransferRegions::chainFor($game->console());

        usort($versions, fn (array $a, array $b): int => self::rank($a, $chain) <=> self::rank($b, $chain));

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
     * The regions a version is, as far as anything can tell: the one recorded
     * on its root — the provider's, or read off the disc — and the ones its
     * name gives.
     *
     * @param  list<GameFile>  $version
     * @return list<string>
     */
    public static function regionsOf(array $version): array
    {
        $root = $version[0] ?? null;

        if ($root === null) {
            return [];
        }

        return array_values(array_unique(array_filter([
            $root->region,
            ...RomRegions::fromFilename((string) $root->filename),
        ], fn (?string $region): bool => $region !== null && $region !== '')));
    }

    /**
     * How good a copy the version is, lowest first.
     *
     * The name is the root's; what the provider says is any file's, since it
     * knows a cuesheet's tracks or a set's discs more often than the sheet
     * or the playlist over them.
     *
     * @param  list<GameFile>  $version
     * @param  list<string>  $chain
     * @return array{int, int, int, int, int, int, string}
     */
    private static function rank(array $version, array $chain): array
    {
        $root = $version[0];
        $flags = array_values(array_unique(array_merge(...array_map(
            fn (GameFile $file): array => $file->provider_flags ?? [],
            $version,
        ))));
        $known = array_filter(array_map(fn (GameFile $file): ?int => $file->scrapes, $version), fn (?int $scrapes): bool => $scrapes !== null);
        $scrapes = $known !== [] ? max($known) : -1;
        preg_match_all('/\(([^)]*)\)|\[([^\]]*)\]/', pathinfo($root->filename, PATHINFO_FILENAME), $matches, PREG_SET_ORDER);

        $unwanted = array_intersect($flags, self::UNWANTED_PROVIDER_FLAGS) !== [] ? 1 : 0;
        $regionRank = count($chain) + 1;
        $revision = 0;

        // What the provider said about this very dump counts as much as its name.
        if ($root->region !== null && $root->region !== '') {
            $at = array_search($root->region, $chain, true);
            $regionRank = $at === false ? count($chain) : $at;
        }
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

            $regions = RomRegions::fromTag($round);

            if ($regions !== []) {
                foreach ($regions as $region) {
                    $at = array_search($region, $chain, true);
                    $regionRank = min($regionRank, $at === false ? count($chain) : $at);
                }

                continue;
            }

            $others++;
        }

        // Negated so that the provider's pick, the most scraped and a later
        // revision sort first; a dump the provider does not know, after any it does.
        return [$unwanted, $regionRank, -(int) in_array('best', $flags, true), -$scrapes, $others, -$revision, $root->path];
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
