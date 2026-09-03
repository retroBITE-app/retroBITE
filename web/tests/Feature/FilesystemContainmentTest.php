<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FilesystemService;
use App\Support\Console;
use RuntimeException;

/**
 * Every write, move and delete must stay inside the console's own directory.
 * These are the paths a request can influence, so each one is asserted directly
 * rather than trusting the controller-level validation above it.
 */
final class FilesystemContainmentTest extends DatabaseTestCase
{
    private FilesystemService $filesystem;
    private Console $console;
    private string $outsideFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new FilesystemService();
        $this->console    = Console::tryFrom('ps2') ?? self::fail('ps2 must be a known console');

        $this->filesystem->createDir($this->console, '');

        $this->outsideFile = (string) config('settings.games_path') . '/../outside.iso';
        touch($this->outsideFile);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        @unlink($this->outsideFile);
    }

    public function test_prepares_a_destination_inside_the_console_root(): void
    {
        $path = $this->filesystem->prepareDestination($this->console, 'Ico.iso', '');

        $this->assertSame($this->console->path() . '/Ico.iso', $path);
    }

    public function test_prepares_a_destination_inside_a_subfolder(): void
    {
        $path = $this->filesystem->prepareDestination($this->console, 'Ico.iso', 'DVD');

        $this->assertSame($this->console->path() . '/DVD/Ico.iso', $path);
        $this->assertDirectoryExists($this->console->path() . '/DVD');
    }

    public function test_strips_directory_components_from_a_filename(): void
    {
        $path = $this->filesystem->prepareDestination($this->console, '../../evil.iso', '');

        $this->assertSame($this->console->path() . '/evil.iso', $path);
    }

    public function test_refuses_a_destination_above_the_console_root(): void
    {
        $this->expectException(RuntimeException::class);

        $this->filesystem->prepareDestination($this->console, 'Ico.iso', '../..');
    }

    public function test_refuses_to_create_a_directory_above_the_console_root(): void
    {
        $this->expectException(RuntimeException::class);

        $this->filesystem->createDir($this->console, '../../escaped');
    }

    public function test_refuses_to_delete_a_file_outside_the_console_root(): void
    {
        $this->assertFalse($this->filesystem->deleteFile($this->console, $this->outsideFile));
        $this->assertFileExists($this->outsideFile);
    }

    public function test_deletes_a_file_inside_the_console_root(): void
    {
        $inside = $this->filesystem->prepareDestination($this->console, 'Doomed.iso', '');
        touch($inside);

        $this->assertTrue($this->filesystem->deleteFile($this->console, $inside));
        $this->assertFileDoesNotExist($inside);
    }

    public function test_refuses_to_delete_a_directory_outside_the_console_root(): void
    {
        $this->assertFalse($this->filesystem->deleteDir($this->console, '../..'));
        $this->assertDirectoryExists((string) config('settings.games_path'));
    }

    public function test_refuses_to_delete_the_console_root_itself(): void
    {
        $this->assertFalse($this->filesystem->deleteDir($this->console, ''));
        $this->assertDirectoryExists($this->console->path());
    }

    public function test_refuses_to_move_a_file_in_from_outside_the_console_root(): void
    {
        $this->expectException(RuntimeException::class);

        $this->filesystem->moveFile($this->console, $this->outsideFile, '');
    }

    public function test_moves_a_file_between_subfolders(): void
    {
        $this->filesystem->createDir($this->console, 'CD');
        $source = $this->filesystem->prepareDestination($this->console, 'Mover.iso', '');
        touch($source);

        $destination = $this->filesystem->moveFile($this->console, $source, 'CD');

        $this->assertSame($this->console->path() . '/CD/Mover.iso', $destination);
        $this->assertFileExists($destination);
        $this->assertFileDoesNotExist($source);

        @unlink($destination);
    }

    public function test_refuses_to_overwrite_an_existing_file_on_move(): void
    {
        $this->filesystem->createDir($this->console, 'CD');
        $source = $this->filesystem->prepareDestination($this->console, 'Clash.iso', '');
        $taken  = $this->filesystem->prepareDestination($this->console, 'Clash.iso', 'CD');
        touch($source);
        touch($taken);

        try {
            $this->expectException(RuntimeException::class);
            $this->filesystem->moveFile($this->console, $source, 'CD');
        } finally {
            @unlink($source);
            @unlink($taken);
        }
    }
}
