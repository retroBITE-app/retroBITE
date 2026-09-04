<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Game;
use App\Repositories\GameMetadataRepository;
use App\Repositories\GameRepository;
use App\Support\Console;
use Illuminate\Database\Capsule\Manager as Capsule;

final class GameRepositoryTest extends DatabaseTestCase
{
    private GameRepository $games;
    private Console $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->games   = new GameRepository();
        $this->console = Console::tryFrom('ps2') ?? self::fail('ps2 must be a known console');

        Capsule::table('games')->delete();
    }

    protected function tearDown(): void
    {
        Capsule::table('games')->delete();

        parent::tearDown();
    }

    /**
     * The card query gained a metadata join. game_metadata is keyed by md5, so
     * it matches at most once per game — the counts must be untouched by it.
     */
    public function test_the_metadata_join_does_not_inflate_the_counts(): void
    {
        $this->seedGame('Ico.iso', '', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->seedGame('Sotc.iso', '', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $this->seedGame('scph39001.bin', 'BIOS');
        (new GameMetadataRepository())->upsert('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', ['title' => 'Ico']);

        $row = $this->games->consoleCounts()['ps2'];

        $this->assertSame(2, $row['game_count']);
        $this->assertSame(1, $row['bios_count']);
        $this->assertSame(1, $row['identified_count']);
    }

    /**
     * BIOS images occupy disk and are counted in the size, but they are not
     * games — so they must never count as identified.
     */
    public function test_bytes_cover_every_file_and_bios_is_never_identified(): void
    {
        $this->seedGame('Ico.iso', '', 'cccccccccccccccccccccccccccccccc', 1_000);
        $this->seedGame('scph39001.bin', 'BIOS', 'cccccccccccccccccccccccccccccccc', 500);
        (new GameMetadataRepository())->upsert('cccccccccccccccccccccccccccccccc', ['title' => 'Ico']);

        $row = $this->games->consoleCounts()['ps2'];

        $this->assertSame(1_500, $row['bytes'], 'BIOS images occupy disk too');
        $this->assertSame(1, $row['identified_count'], 'the BIOS row shares the md5 but is not a game');
    }

    public function test_the_card_carries_its_folder_and_figures(): void
    {
        $this->seedGame('Ico.iso', '', null, 2_048);

        $card = $this->console->toCardArray($this->games->consoleCounts()->get('ps2'));

        $this->assertStringEndsWith('/ps2', $card['path']);
        $this->assertSame(1, $card['game_count']);
        $this->assertSame(0, $card['identified_count']);
        $this->assertSame(2_048, $card['bytes']);
    }

    public function test_counts_games_and_bios_separately(): void
    {
        $this->seedGame('Ico.iso', '');
        $this->seedGame('Sotc.iso', 'DVD');
        $this->seedGame('scph39001.bin', 'BIOS');

        $counts = $this->games->consoleCounts();

        $this->assertSame(2, $counts['ps2']['game_count']);
        $this->assertSame(1, $counts['ps2']['bios_count']);
    }

    public function test_scopes_to_the_root_folder(): void
    {
        $this->seedGame('Ico.iso', '');
        $this->seedGame('Sotc.iso', 'DVD');

        $found = $this->games->allForConsoleFolders($this->console, ['root']);

        $this->assertSame(['Ico.iso'], $found->pluck('file_name')->all());
    }

    public function test_scopes_to_a_named_subfolder(): void
    {
        $this->seedGame('Ico.iso', '');
        $this->seedGame('Sotc.iso', 'DVD');

        $found = $this->games->allForConsoleFolders($this->console, ['DVD']);

        $this->assertSame(['Sotc.iso'], $found->pluck('file_name')->all());
    }

    public function test_unions_several_folders(): void
    {
        $this->seedGame('Ico.iso', '');
        $this->seedGame('Sotc.iso', 'DVD');
        $this->seedGame('Gt4.iso', 'CD');

        $found = $this->games->allForConsoleFolders($this->console, ['DVD', 'CD']);

        $this->assertEqualsCanonicalizing(['Sotc.iso', 'Gt4.iso'], $found->pluck('file_name')->all());
    }

    /**
     * A LIKE wildcard must not widen the filter — SQLite's LIKE takes no escape
     * character, so the folder value is validated upstream instead.
     */
    public function test_a_wildcard_folder_matches_nothing(): void
    {
        $this->seedGame('Ico.iso', '');
        $this->seedGame('Sotc.iso', 'DVD');

        $this->assertCount(0, $this->games->allForConsoleFolders($this->console, ['%']));
    }

    public function test_folder_counts_cover_all_root_and_each_subfolder(): void
    {
        $this->seedGame('Ico.iso', '');
        $this->seedGame('Sotc.iso', 'DVD');

        $pills = $this->games->folderCounts($this->console, ['DVD']);

        $this->assertSame(['', 'root', 'DVD'], array_column($pills, 'value'));
        $this->assertSame([2, 1, 1], array_column($pills, 'count'));
    }

    public function test_deletes_only_the_named_folders_rows(): void
    {
        $this->seedGame('Ico.iso', '');
        $this->seedGame('Sotc.iso', 'DVD');

        $this->assertSame(1, $this->games->deleteByFolder($this->console, 'DVD'));
        $this->assertSame(['Ico.iso'], Game::pluck('file_name')->all());
    }

    /**
     * Insert a game row whose path sits in the given subfolder of ps2.
     */
    /**
     * The folder on disk is lowercase "bios" in practice. SQLite's LIKE matches
     * it either way, so the model must too — otherwise the payload disagrees
     * with every count on the page.
     */
    public function test_bios_detection_ignores_the_folder_case(): void
    {
        $this->seedGame('scph39001.bin', 'bios');
        $this->seedGame('scph10000.bin', 'BIOS');
        $this->seedGame('Ico.iso', '');

        $flags = $this->games->allForConsoleFolders($this->console, [])
            ->mapWithKeys(fn(Game $game) => [$game->file_name => $game->isBios()])
            ->all();

        $this->assertTrue($flags['scph39001.bin'], 'a lowercase bios folder still holds BIOS files');
        $this->assertTrue($flags['scph10000.bin']);
        $this->assertFalse($flags['Ico.iso']);
        $this->assertSame(2, $this->games->consoleCounts()['ps2']['bios_count']);
    }

    private function seedGame(
        string $fileName,
        string $subfolder,
        ?string $md5 = null,
        int $size = 1024,
    ): void {
        $this->games->upsert(
            $this->console->key,
            $fileName,
            $this->console->path($subfolder) . '/' . $fileName,
            $size,
            $md5,
        );
    }
}
