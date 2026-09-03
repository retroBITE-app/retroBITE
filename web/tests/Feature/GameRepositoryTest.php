<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Game;
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
    private function seedGame(string $fileName, string $subfolder): void
    {
        $this->games->upsert(
            $this->console->key,
            $fileName,
            $this->console->path($subfolder) . '/' . $fileName,
            1024,
            null,
        );
    }
}
