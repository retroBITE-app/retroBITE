<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\GameMetadataRepository;
use App\Repositories\GameRepository;
use App\Services\HostSummaryService;
use App\Support\Console;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * The login page reads a random backdrop and the host figures. A fresh install
 * has neither, and must still render.
 */
final class LoginPageDataTest extends DatabaseTestCase
{
    private GameMetadataRepository $metadata;
    private GameRepository $games;
    private Console $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metadata = new GameMetadataRepository();
        $this->games    = new GameRepository();
        $this->console  = Console::tryFrom('ps2') ?? self::fail('ps2 must be a known console');

        Capsule::table('game_metadata')->delete();
        Capsule::table('games')->delete();
    }

    protected function tearDown(): void
    {
        Capsule::table('game_metadata')->delete();
        Capsule::table('games')->delete();

        parent::tearDown();
    }

    public function test_no_backdrop_when_nothing_is_cached(): void
    {
        $this->assertNull($this->metadata->randomBackdropUrl());
    }

    public function test_returns_the_only_cached_backdrop(): void
    {
        $this->seedMetadata('a', '/storage/metadata/a_backdrop.jpg');

        $this->assertSame('/storage/metadata/a_backdrop.jpg', $this->metadata->randomBackdropUrl());
    }

    public function test_ignores_rows_with_no_backdrop(): void
    {
        $this->seedMetadata('a', null);
        $this->seedMetadata('b', '');
        $this->seedMetadata('c', '/storage/metadata/c_backdrop.jpg');

        $this->assertSame('/storage/metadata/c_backdrop.jpg', $this->metadata->randomBackdropUrl());
    }

    /**
     * Over enough draws from three rows, more than one distinct value must come
     * back — otherwise the ordering is not random and every visit looks the same.
     */
    public function test_varies_across_visits(): void
    {
        foreach (['a', 'b', 'c'] as $key) {
            $this->seedMetadata($key, "/storage/metadata/{$key}_backdrop.jpg");
        }

        $seen = [];

        for ($i = 0; $i < 40; $i++) {
            $seen[(string) $this->metadata->randomBackdropUrl()] = true;
        }

        $this->assertGreaterThan(1, count($seen), 'the backdrop should not be the same every time');
    }

    public function test_library_summary_is_zero_on_an_empty_library(): void
    {
        $this->assertSame(
            ['game_count' => 0, 'bios_count' => 0, 'bytes' => 0],
            $this->games->librarySummary(),
        );
    }

    public function test_library_summary_counts_rows_and_bytes(): void
    {
        $this->seedGame('Ico.iso', 1024);
        $this->seedGame('Sotc.iso', 2048);

        $this->assertSame(
            ['game_count' => 2, 'bios_count' => 0, 'bytes' => 3072],
            $this->games->librarySummary(),
        );
    }

    /**
     * A BIOS image is a library row but not a game — the login page said
     * "94 games catalogued" while counting them.
     */
    public function test_library_summary_keeps_bios_out_of_the_game_count(): void
    {
        $this->seedGame('Ico.iso', 1024);
        $this->seedGame('scph39001.bin', 512, 'BIOS');

        $this->assertSame(
            ['game_count' => 1, 'bios_count' => 1, 'bytes' => 1536],
            $this->games->librarySummary(),
            'bytes still covers both, since a BIOS image occupies disk',
        );
    }

    public function test_login_summary_excludes_bios_from_the_game_count(): void
    {
        $this->seedGame('Ico.iso', 1024);
        $this->seedGame('scph39001.bin', 512, 'BIOS');
        $this->seedGame('scph70012.bin', 512, 'BIOS');

        $summary = (new HostSummaryService($this->games))->loginSummary();

        $this->assertIsArray($summary);
        $this->assertSame('1', $summary[0]['value']);
        $this->assertSame('game catalogued', $summary[0]['label']);
    }

    public function test_login_summary_reports_three_figures(): void
    {
        $this->seedGame('Ico.iso', 5 * 1024 * 1024 * 1024);

        $summary = (new HostSummaryService($this->games))->loginSummary();

        $this->assertIsArray($summary);
        $this->assertCount(3, $summary);
        $this->assertSame('1', $summary[0]['value']);
        $this->assertSame('game catalogued', $summary[0]['label'], 'the label should be singular for one game');
        $this->assertSame('console installed', $summary[1]['label'], 'and singular for one console');
        $this->assertSame('5 GB', $summary[2]['value']);
    }

    /**
     * Store one metadata row directly — upsert() would demand a whole payload.
     */
    private function seedMetadata(string $md5, ?string $backdrop): void
    {
        Capsule::table('game_metadata')->insert([
            'md5'          => $md5,
            'provider'     => 'screenscraper',
            'backdrop_url' => $backdrop,
            'fetched_at'   => time(),
        ]);
    }

    private function seedGame(string $fileName, int $size, string $subfolder = ''): void
    {
        $this->games->upsert(
            $this->console->key,
            $fileName,
            $this->console->path($subfolder) . '/' . $fileName,
            $size,
            null,
        );
    }
}
