<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Game;
use App\Repositories\GameMetadataRepository;
use App\Repositories\GameRepository;
use App\Services\DashboardService;
use App\Services\GameDataService;
use App\Support\Console;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Arr;

/**
 * The dashboard leads with the newest arrival and lists what still needs
 * identifying, so both orderings have to hold up — including across a rescan.
 */
final class DashboardDataTest extends DatabaseTestCase
{
    private GameRepository $games;
    private GameMetadataRepository $metadata;
    private DashboardService $dashboard;
    private Console $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->games     = new GameRepository();
        $this->metadata  = new GameMetadataRepository();
        $this->dashboard = new DashboardService($this->games, new GameDataService());
        $this->console   = Console::tryFrom('ps2') ?? self::fail('ps2 must be a known console');

        Capsule::table('games')->delete();
        Capsule::table('game_metadata')->delete();
    }

    protected function tearDown(): void
    {
        Capsule::table('games')->delete();
        Capsule::table('game_metadata')->delete();

        parent::tearDown();
    }

    public function test_recently_added_is_newest_first_and_skips_bios(): void
    {
        $this->seedGame('Ico.iso', firstSeen: 1_000);
        $this->seedGame('Sotc.iso', firstSeen: 3_000);
        $this->seedGame('scph39001.bin', firstSeen: 5_000, subfolder: 'BIOS');

        $names = $this->games->recentlyAdded(10)->pluck('file_name')->all();

        $this->assertSame(['Sotc.iso', 'Ico.iso'], $names);
    }

    /**
     * `first_seen_at` is absent from upsert's update list, so rescanning a
     * library must not reshuffle "recently added".
     */
    public function test_a_rescan_does_not_reorder_recently_added(): void
    {
        $this->seedGame('Ico.iso', firstSeen: 1_000);
        $this->seedGame('Sotc.iso', firstSeen: 3_000);

        $this->games->upsert('ps2', 'Ico.iso', $this->console->path() . '/Ico.iso', 2048);

        $names = $this->games->recentlyAdded(10)->pluck('file_name')->all();

        $this->assertSame(['Sotc.iso', 'Ico.iso'], $names);
    }

    public function test_unidentified_covers_both_a_missing_hash_and_a_missing_row(): void
    {
        $this->seedGame('NoHash.iso', firstSeen: 1_000, md5: null);
        $this->seedGame('Unmatched.iso', firstSeen: 2_000, md5: 'aaaa');
        $this->seedGame('Known.iso', firstSeen: 3_000, md5: 'bbbb');
        $this->metadata->upsert('bbbb', ['title' => 'Known']);

        $names = $this->games->unidentified(10)->pluck('file_name')->all();

        $this->assertSame(['Unmatched.iso', 'NoHash.iso'], $names);
        $this->assertSame(
            ['identified' => 1, 'unidentified' => 2],
            $this->games->identificationCounts(),
        );
    }

    /**
     * A hero with no artwork is a worse card than a slightly older one with it.
     */
    public function test_the_hero_prefers_a_game_with_a_backdrop(): void
    {
        $this->seedGame('WithArt.iso', firstSeen: 1_000, md5: 'cccc');
        $this->seedGame('Newest.iso', firstSeen: 9_000, md5: 'dddd');
        $this->metadata->upsert('cccc', ['backdrop_url' => '/storage/metadata/cccc/backdrop.jpg']);

        $payload = $this->dashboard->payload();

        $this->assertSame('WithArt.iso', $payload['hero']['file_name']);
        $this->assertSame('ps2', $payload['hero']['console']);
        $this->assertSame('PlayStation 2', $payload['hero']['console_name']);
        $this->assertSame(
            ['Newest.iso'],
            Arr::pluck($payload['recent'], 'file_name'),
            'the hero must not repeat in the column beside it',
        );
    }

    public function test_an_empty_library_still_yields_a_payload(): void
    {
        $payload = $this->dashboard->payload();

        $this->assertNull($payload['hero']);
        $this->assertSame([], $payload['recent']);
        $this->assertSame([], $payload['unmatched']['rows']);
        $this->assertSame(0, $payload['unmatched']['total']);
        $this->assertNotEmpty($payload['stats'], 'the figures render even with nothing to count');
    }

    private function seedGame(
        string $fileName,
        int $firstSeen,
        string $subfolder = '',
        ?string $md5 = 'ffff',
    ): void {
        $this->games->upsert(
            $this->console->key,
            $fileName,
            $this->console->path($subfolder) . '/' . $fileName,
            1024,
            $md5,
        );

        Game::query()->where('file_name', $fileName)->update(['first_seen_at' => $firstSeen]);
    }
}
