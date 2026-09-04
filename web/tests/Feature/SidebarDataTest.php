<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\GameRepository;
use App\Services\HostSummaryService;
use App\Services\SidebarService;
use App\Support\Console;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Arr;

/**
 * The sidebar appears on every authenticated page, so its data has to hold up on
 * a fresh install as well as a populated one.
 */
final class SidebarDataTest extends DatabaseTestCase
{
    private GameRepository $games;
    private HostSummaryService $host;
    private SidebarService $sidebar;
    private Console $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->games   = new GameRepository();
        $this->host    = new HostSummaryService($this->games);
        $this->sidebar = new SidebarService($this->games, $this->host);
        $this->console = Console::tryFrom('ps2') ?? self::fail('ps2 must be a known console');

        Capsule::table('games')->delete();
    }

    protected function tearDown(): void
    {
        Capsule::table('games')->delete();

        parent::tearDown();
    }

    public function test_payload_carries_consoles_and_storage(): void
    {
        $payload = $this->sidebar->payload();

        $this->assertArrayHasKey('consoles', $payload);
        $this->assertArrayHasKey('storage', $payload);
    }

    public function test_lists_only_installed_consoles(): void
    {
        $this->installConsole();

        $keys = Arr::pluck($this->sidebar->payload()['consoles'], 'key');

        $this->assertContains('ps2', $keys);
        $this->assertNotContains('snes', $keys, 'a console with no directory is not installed');
        $this->assertLessThan(
            count((array) config('consoles')),
            count($keys),
            'the sidebar lists installed consoles, not every configured one',
        );
    }

    public function test_each_console_row_carries_what_the_sidebar_renders(): void
    {
        $this->installConsole();
        $this->seedGame('Ico.iso', 1024);

        $row = Arr::first(
            $this->sidebar->payload()['consoles'],
            fn(array $console) => $console['key'] === 'ps2',
        );

        $this->assertNotNull($row);
        $this->assertSame('PlayStation 2', $row['name']);
        $this->assertSame(1, $row['game_count']);
        $this->assertNotSame('', $row['icon'], 'the row renders the console artwork');
    }

    /**
     * BIOS images are library rows but not games, so the sidebar count must skip
     * them the same way the login stats do.
     */
    public function test_console_counts_exclude_bios(): void
    {
        $this->installConsole();
        $this->seedGame('Ico.iso', 1024);
        $this->seedGame('scph39001.bin', 512, 'BIOS');

        $row = Arr::first(
            $this->sidebar->payload()['consoles'],
            fn(array $console) => $console['key'] === 'ps2',
        );

        $this->assertSame(1, $row['game_count']);
        $this->assertSame(1, $row['bios_count']);
    }

    public function test_storage_reads_zero_on_an_empty_library(): void
    {
        $meter = $this->host->storageMeter();

        $this->assertSame('0 B', $meter['used']);
        $this->assertSame(0, $meter['percent'], 'an empty library fills none of the volume');
    }

    public function test_storage_reports_the_library_size(): void
    {
        $this->installConsole();
        $this->seedGame('Ico.iso', 5 * 1024 * 1024 * 1024);

        $meter = $this->host->storageMeter();

        $this->assertSame('5 GB', $meter['used']);
        $this->assertNotNull($meter['total'], 'the scratch volume should report a capacity');
        $this->assertIsInt($meter['percent']);
        $this->assertGreaterThanOrEqual(0, $meter['percent']);
        $this->assertLessThanOrEqual(100, $meter['percent']);
    }

    /**
     * With no volume to measure there is no denominator, and the sidebar shows
     * the figure without a bar rather than inventing one.
     */
    public function test_storage_has_no_denominator_when_the_volume_is_unreadable(): void
    {
        $meter = $this->host->storageMeter('/nonexistent-' . bin2hex(random_bytes(4)));

        $this->assertNull($meter['total']);
        $this->assertNull($meter['percent']);
        $this->assertSame('0 B', $meter['used'], 'the library size is still known');
    }

    private function installConsole(): void
    {
        $path = $this->console->path();

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
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
