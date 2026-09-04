<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FilesystemService;
use App\Support\Console;
use RuntimeException;

/**
 * Assembly must never publish a partial file. A missing chunk previously reached
 * stream_copy_to_stream(false, …) and fatalled after truncating the destination.
 */
final class ChunkedUploadTest extends DatabaseTestCase
{
    private const UPLOAD_ID = '9c74df04-79f1-480b-a2be-80596030843a';

    private FilesystemService $filesystem;
    private Console $console;
    private string $stagingDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new FilesystemService();
        $this->console    = Console::tryFrom('ps2') ?? self::fail('ps2 must be a known console');
        $this->stagingDir = (string) config('settings.tmp_path') . '/' . self::UPLOAD_ID;

        $this->filesystem->createDir($this->console, '');

        if (!is_dir($this->stagingDir)) {
            mkdir($this->stagingDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (glob($this->stagingDir . '/*') ?: [] as $part) {
            @unlink($part);
        }
        @rmdir($this->stagingDir);
        @unlink($this->destination());
    }

    public function test_assembles_chunks_in_order(): void
    {
        $this->stageChunks(['AAA', 'BBB', 'CCC']);

        $this->filesystem->assembleFile(self::UPLOAD_ID, 3, $this->destination());

        $this->assertSame('AAABBBCCC', file_get_contents($this->destination()));
    }

    public function test_clears_the_staging_directory_once_assembled(): void
    {
        $this->stageChunks(['AAA']);

        $this->filesystem->assembleFile(self::UPLOAD_ID, 1, $this->destination());

        $this->assertDirectoryDoesNotExist($this->stagingDir);
    }

    public function test_refuses_to_assemble_when_a_chunk_is_missing(): void
    {
        // Only the final chunk was sent, which is what routes to assembly.
        file_put_contents($this->stagingDir . '/4.part', 'DDD');

        try {
            $this->filesystem->assembleFile(self::UPLOAD_ID, 5, $this->destination());
            self::fail('assembly should have been refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('chunk 0 is missing', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->destination(), 'no partial file may be left behind');
    }

    public function test_refuses_to_overwrite_an_existing_file(): void
    {
        touch($this->destination());
        $this->stageChunks(['AAA']);

        $this->expectException(RuntimeException::class);

        $this->filesystem->assembleFile(self::UPLOAD_ID, 1, $this->destination());
    }

    public function test_refuses_a_malformed_upload_id(): void
    {
        $this->expectException(RuntimeException::class);

        $this->filesystem->assembleFile('../../etc', 1, $this->destination());
    }

    /**
     * @param string[] $contents
     */
    private function stageChunks(array $contents): void
    {
        foreach ($contents as $index => $content) {
            file_put_contents($this->stagingDir . '/' . $index . '.part', $content);
        }
    }

    private function destination(): string
    {
        return $this->console->path() . '/Assembled.iso';
    }
}
