<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Console;
use App\Support\LaunchBox\LaunchBoxTitle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;

/**
 * The local copy of the LaunchBox Games Database's ratings.
 *
 * Built the way the RetroAchievements hash index is: one download, then
 * every lookup is a query here. The database publishes itself as a single
 * public zip, rebuilt daily, with no account and no key — over a hundred
 * megabytes, of which this keeps a few: for each game on a platform some
 * console maps to (Console::$launchboxPlatforms), its name, the players'
 * average and how many voted, and every alternate name it goes by.
 *
 * Read straight out of the zip with XMLReader, one top-level element at a
 * time, because the XML inside is half a gigabyte. Written in one
 * transaction, so a download that breaks halfway leaves last week's index
 * standing rather than an empty one.
 */
final class LaunchBoxIndex
{
    /** Rows per insert; MariaDB stops at 65 535 placeholders a statement. */
    private const CHUNK = 500;

    /**
     * Download the database and rebuild the index from it.
     *
     * @return array{platforms: int, games: int, names: int}
     */
    public function sync(): array
    {
        $directory = storage_path('app/launchbox');
        File::ensureDirectoryExists($directory);
        $zip = $directory.'/Metadata.zip';

        try {
            Http::timeout((int) config('launchbox.timeout'))
                ->connectTimeout(30)
                ->sink($zip)
                ->get((string) config('launchbox.metadata_url'))
                ->throw();

            return $this->import('zip://'.$zip.'#Metadata.xml');
        } finally {
            File::delete($zip);
        }
    }

    /**
     * Rebuild the index from the database's Metadata.xml.
     *
     * @return array{platforms: int, games: int, names: int}
     */
    public function import(string $uri): array
    {
        $wanted = array_flip(self::platforms());

        if ($wanted === []) {
            return ['platforms' => 0, 'games' => 0, 'names' => 0];
        }

        $reader = new XMLReader;

        if (! @$reader->open($uri, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException("LaunchBox metadata could not be opened: {$uri}");
        }

        try {
            return DB::transaction(fn () => $this->rebuild($reader, $wanted));
        } finally {
            $reader->close();
        }
    }

    /**
     * Every platform name some console reads ratings from.
     *
     * @return array<int, string>
     */
    public static function platforms(): array
    {
        return Console::all()
            ->flatMap(fn (Console $console) => $console->launchboxPlatforms)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int>  $wanted
     * @return array{platforms: int, games: int, names: int}
     */
    private function rebuild(XMLReader $reader, array $wanted): array
    {
        DB::table('launchbox_names')->delete();
        DB::table('launchbox_games')->delete();
        DB::table('launchbox_platforms')->delete();

        // The platform of every game kept, by id, as its index in $platforms
        // rather than its name: an alternate name says which game it belongs
        // to and nothing else, and a hundred and seventy thousand copies of
        // "Super Nintendo Entertainment System" would cost tens of megabytes
        // under a 128M limit. Alternate names follow all the games in the
        // dump, so each is kept or dropped as it is read.
        $platforms = array_keys($wanted);
        $kept = [];
        $games = [];
        $names = [];
        $nameCount = 0;

        // Onto the root, then onto its first child; next() then walks the
        // root's children without descending into any of them, which is what
        // keeps a million GameImage elements from being read one by one.
        $reader->read();
        $reader->read();

        do {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->name === 'Game') {
                $game = $this->element($reader);
                $platform = trim((string) $game->Platform);
                $id = (int) $game->DatabaseID;

                if ($id <= 0 || ! isset($wanted[$platform])) {
                    continue;
                }

                $votes = (int) $game->CommunityRatingCount;
                $name = trim((string) $game->Name);
                $kept[$id] = $wanted[$platform];

                $games[] = [
                    'id' => $id,
                    'platform' => $platform,
                    'name' => mb_substr($name, 0, 255),
                    'rating' => $votes > 0 ? (float) $game->CommunityRating : null,
                    'votes' => $votes,
                ];

                $names[] = $this->nameRow($id, $platform, $name, false);

                if (count($games) >= self::CHUNK) {
                    DB::table('launchbox_games')->insert($games);
                    $games = [];
                }

                if (count($names) >= self::CHUNK) {
                    $nameCount += $this->insertNames($names);
                    $names = [];
                }
            } elseif ($reader->name === 'GameAlternateName') {
                $alias = $this->element($reader);
                $id = (int) $alias->DatabaseID;

                if (isset($kept[$id])) {
                    $names[] = $this->nameRow($id, $platforms[$kept[$id]], trim((string) $alias->AlternateName), true);
                }

                if (count($names) >= self::CHUNK) {
                    $nameCount += $this->insertNames($names);
                    $names = [];
                }
            }
        } while ($reader->next());

        if ($games !== []) {
            DB::table('launchbox_games')->insert($games);
        }

        if ($kept === []) {
            // A zip that unpacked to something else entirely — an error page,
            // a renamed file. Thrown so the transaction keeps the old index.
            throw new RuntimeException('LaunchBox metadata held no games for any configured platform.');
        }

        $nameCount += $this->insertNames($names);

        $platforms = $this->measurePlatforms();

        Log::info('LaunchBox index rebuilt.', [
            'platforms' => $platforms,
            'games' => count($kept),
            'names' => $nameCount,
        ]);

        return ['platforms' => $platforms, 'games' => count($kept), 'names' => $nameCount];
    }

    /**
     * The element under the reader, parsed on its own. A copy rather than
     * expand(), whose node is freed the moment the reader moves on.
     */
    private function element(XMLReader $reader): SimpleXMLElement
    {
        $element = simplexml_load_string($reader->readOuterXml(), options: LIBXML_NONET | LIBXML_COMPACT);

        if ($element === false) {
            throw new RuntimeException('LaunchBox metadata is not well-formed.');
        }

        return $element;
    }

    /**
     * @return array{launchbox_game_id: int, platform: string, name_key: string, alias: bool}
     */
    private function nameRow(int $id, string $platform, string $name, bool $alias): array
    {
        return [
            'launchbox_game_id' => $id,
            'platform' => $platform,
            'name_key' => mb_substr(LaunchBoxTitle::key($name), 0, 255),
            'alias' => $alias,
        ];
    }

    /**
     * Write name rows, leaving out any that reduced to nothing.
     *
     * @param  list<array{launchbox_game_id: int, platform: string, name_key: string, alias: bool}>  $rows
     */
    private function insertNames(array $rows): int
    {
        $rows = array_values(array_filter($rows, fn (array $row) => $row['name_key'] !== ''));

        if ($rows !== []) {
            DB::table('launchbox_names')->insert($rows);
        }

        return count($rows);
    }

    /**
     * Each platform's average, which the score pulls a thinly voted game
     * towards. Weighted by votes, so it is the average vote on the platform
     * rather than the average game.
     */
    private function measurePlatforms(): int
    {
        $now = now();

        $rows = DB::table('launchbox_games')
            ->selectRaw('platform, SUM(rating * votes) / NULLIF(SUM(votes), 0) AS mean_rating, COUNT(*) AS games, SUM(votes > 0) AS rated')
            ->groupBy('platform')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->platform,
                'mean_rating' => $row->mean_rating !== null ? (float) $row->mean_rating : null,
                'games' => (int) $row->games,
                'rated' => (int) $row->rated,
                'synced_at' => $now,
            ])
            ->all();

        DB::table('launchbox_platforms')->insert($rows);

        return count($rows);
    }
}
