<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Game;
use App\Repositories\GameRepository;
use App\Services\FilesystemService;
use App\Services\LibraryScanService;
use App\Repositories\SettingRepository;
use App\Support\Console;
use App\Support\SettingsOverrides;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Scanning used to hash every file inline and died on PHP's execution limit
 * partway through a PS2 shelf. These cover the split that replaced it.
 */
final class LibraryScanTest extends DatabaseTestCase
{
    /** Not ps2: every other Feature test fixtures that directory, and these own theirs. */
    private const CONSOLE = 'gc';

    private GameRepository $games;
    private LibraryScanService $scanner;
    private Console $console;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->games   = new GameRepository();
        $this->scanner = new LibraryScanService(new FilesystemService(), $this->games);
        $this->console = Console::tryFrom(self::CONSOLE) ?? self::fail(self::CONSOLE . ' must be a known console');
        $this->dir     = $this->console->path();

        Capsule::table('games')->delete();
        $this->clearDir();
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->clearDir();

        Capsule::table('games')->delete();

        parent::tearDown();
    }

    /**
     * Remove the console directory outright. Globbing one extension left the
     * .bin fixtures behind, and every later test then scanned them.
     */
    private function clearDir(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    /**
     * The whole point of the split: indexing must not read a byte of the file,
     * so a library of multi-GB ISOs lands in one fast request.
     */
    public function test_indexing_records_files_without_hashing_them(): void
    {
        $this->writeGame('Ico.iso', 'ico');
        $this->writeGame('Sotc.iso', 'sotc');

        $result = $this->scanner->index($this->console);

        $this->assertSame(2, $result['found']);
        $this->assertSame(2, $result['remaining']);
        $this->assertSame([null, null], Game::orderBy('file_name')->pluck('file_md5')->all());
    }

    /**
     * The hashing pass fills in what indexing left, and reports an empty backlog
     * so the caller stops polling.
     */
    public function test_hashing_fills_in_every_digest_and_clears_the_backlog(): void
    {
        $this->writeGame('Ico.iso', 'ico');
        $this->scanner->index($this->console);

        $result = $this->scanner->hashPending($this->console, budgetSeconds: 0.0);

        $this->assertSame(1, $result['hashed']);
        $this->assertSame(0, $result['remaining']);
        $this->assertSame(md5('ico'), Game::first()->file_md5);
    }

    /**
     * A batch is resumable: the budget stops it early and a second call finishes
     * the rest, which is what the page's poll loop relies on.
     */
    public function test_a_spent_budget_leaves_the_rest_for_the_next_batch(): void
    {
        $this->writeGame('Ico.iso', 'ico');
        $this->writeGame('Sotc.iso', 'sotc');
        $this->scanner->index($this->console);

        // Any elapsed time overruns a zero-length budget, so exactly one file
        // is hashed — the "always make progress" guarantee.
        $first = $this->scanner->hashPending($this->console, budgetSeconds: 0.000001);

        $this->assertSame(1, $first['hashed']);
        $this->assertSame(1, $first['remaining']);

        $second = $this->scanner->hashPending($this->console, budgetSeconds: 0.0);

        $this->assertSame(1, $second['hashed']);
        $this->assertSame(0, $second['remaining']);
    }

    /**
     * A rescan must not re-hash what it already has, or every scan would cost as
     * much as the first one.
     */
    public function test_a_rescan_keeps_the_digest_of_an_unchanged_file(): void
    {
        $this->writeGame('Ico.iso', 'ico');
        $this->scanner->index($this->console);
        $this->scanner->hashPending($this->console, budgetSeconds: 0.0);

        $this->assertSame(0, $this->scanner->index($this->console)['remaining']);
        $this->assertSame(md5('ico'), Game::first()->file_md5);
    }

    /**
     * A file whose size changed is a different file; its stored digest describes
     * content that is gone, so it must be queued for rehashing.
     */
    public function test_a_resized_file_is_queued_for_rehashing(): void
    {
        $this->writeGame('Ico.iso', 'ico');
        $this->scanner->index($this->console);
        $this->scanner->hashPending($this->console, budgetSeconds: 0.0);

        $this->writeGame('Ico.iso', 'ico-patched');

        $this->assertSame(1, $this->scanner->index($this->console)['remaining']);
        $this->assertNull(Game::first()->file_md5);

        $this->scanner->hashPending($this->console, budgetSeconds: 0.0);

        $this->assertSame(md5('ico-patched'), Game::first()->file_md5);
    }

    /**
     * An unreadable row must end the pass rather than being re-queried forever —
     * the loop refetches from the same pending set it is draining.
     */
    public function test_a_file_that_cannot_be_read_does_not_spin_the_batch(): void
    {
        $this->writeGame('Ico.iso', 'ico');
        $this->scanner->index($this->console);
        unlink($this->dir . '/Ico.iso');

        $result = $this->scanner->hashPending($this->console, budgetSeconds: 0.0);

        $this->assertSame(0, $result['hashed']);
        $this->assertSame(1, $result['remaining']);
    }

    /**
     * Adding a file to `exclude_files` has to remove it from a library it is
     * already in. Pruning on existence alone kept games.bin listed forever,
     * because OPL's index file is still sitting there on disk.
     */
    public function test_excluding_a_file_removes_the_row_it_already_had(): void
    {
        $this->writeGame('Ico.iso', 'ico');
        $this->writeGame('games.bin', 'opl index');

        $this->assertSame(2, $this->scanner->index($this->console)['found']);

        $this->override(['exclude_files' => ['games.bin']]);

        $result = $this->scanner->index($this->console);

        $this->assertSame(1, $result['found']);
        $this->assertSame(1, $result['pruned']);
        $this->assertSame(['Ico.iso'], Game::pluck('file_name')->all());
    }

    /**
     * Same for narrowing the extension list: a .bin the console no longer
     * accepts must leave, not linger from the scan that predates the change.
     */
    public function test_dropping_an_extension_removes_the_rows_it_covered(): void
    {
        $this->writeGame('Ico.iso', 'ico');
        $this->writeGame('scph39001.bin', 'bios');
        $this->scanner->index($this->console);

        $this->override(['file_extensions' => ['iso'], 'bios_extensions' => []]);

        $this->assertSame(1, $this->scanner->index($this->console)['pruned']);
        $this->assertSame(['Ico.iso'], Game::pluck('file_name')->all());
    }

    /**
     * A file that vanished still has to go — the case the old prune handled, and
     * the one the keep-list must not lose.
     */
    public function test_a_deleted_file_still_loses_its_row(): void
    {
        $this->writeGame('Ico.iso', 'ico');
        $this->writeGame('Sotc.iso', 'sotc');
        $this->scanner->index($this->console);

        unlink($this->dir . '/Sotc.iso');

        $this->assertSame(1, $this->scanner->index($this->console)['pruned']);
        $this->assertSame(['Ico.iso'], Game::pluck('file_name')->all());
    }

    /**
     * An unmounted share yields nothing, and pruning on that would wipe the
     * console's whole library rather than report an empty directory.
     */
    public function test_an_absent_directory_prunes_nothing(): void
    {
        $this->writeGame('Ico.iso', 'ico');
        $this->scanner->index($this->console);

        unlink($this->dir . '/Ico.iso');
        rmdir($this->dir);

        $result = $this->scanner->index($this->console);

        $this->assertSame(0, $result['pruned']);
        $this->assertSame(['Ico.iso'], Game::pluck('file_name')->all());
    }

    /**
     * Change console fields the way the Settings UI does, so the test exercises
     * the same override path the user hits, then re-read the console from it.
     *
     * All of them at once: the override is one row per console, so a second call
     * would drop what the first set.
     */
    private function override(array $fields): void
    {
        (new SettingRepository())->upsert('consoles', self::CONSOLE, $fields);
        SettingsOverrides::invalidate();

        $this->console = Console::tryFrom(self::CONSOLE) ?? self::fail(self::CONSOLE . ' must be a known console');
    }

    private function writeGame(string $fileName, string $contents): void
    {
        file_put_contents($this->dir . '/' . $fileName, $contents);
    }
}
