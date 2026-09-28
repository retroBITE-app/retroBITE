<?php

declare(strict_types=1);

namespace App\Transfers\Endpoints;

use App\Enums\TransferFailure;
use App\Exceptions\TransferFailed;
use Illuminate\Support\Str;
use Throwable;

/**
 * An endpoint on this machine's own disks.
 *
 * Subclasses say how a path is admitted — {@see absolute()} — and whether
 * anything may be written; the copying is the same for all of them.
 */
abstract class LocalEndpoint implements Endpoint
{
    /**
     * The absolute path for one inside this endpoint, admitted or refused.
     *
     * @throws TransferFailed
     */
    abstract protected function absolute(string $path): string;

    /** Whether files may be written here at all. */
    abstract protected function writable(): bool;

    /** The folder free space is read from. */
    abstract protected function root(): string;

    public function localFile(string $path): string
    {
        $absolute = $this->absolute($path);

        if (is_link($absolute) || ! is_file($absolute) || ! is_readable($absolute)) {
            throw TransferFailed::because(TransferFailure::SourceMissing, $path);
        }

        return $absolute;
    }

    public function sizeOf(string $path): ?int
    {
        $absolute = $this->absolute($path);

        clearstatcache(true, $absolute);

        if (is_link($absolute)) {
            return -1;
        }

        if (! file_exists($absolute)) {
            return null;
        }

        return is_file($absolute) ? (int) filesize($absolute) : -1;
    }

    public function freeBytes(): ?int
    {
        $free = @disk_free_space($this->root());

        return $free === false ? null : (int) $free;
    }

    public function prepare(string $directory): void
    {
        $this->assertWritable($directory);

        try {
            $this->makeDirectory($directory);

            $probe = $this->join($directory, '.retrobite-probe-'.Str::random(8));
            $absolute = $this->absolute($probe);

            if (@file_put_contents($absolute, 'retrobite') !== 9) {
                throw TransferFailed::because(TransferFailure::Unwritable, $directory);
            }

            @unlink($absolute);
        } catch (TransferFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            throw TransferFailed::because(TransferFailure::Unwritable, $directory, $e);
        }
    }

    public function canAdopt(Endpoint $from): bool
    {
        return false;
    }

    public function adopt(Endpoint $from, string $fromPath, string $path): void
    {
        throw TransferFailed::because(TransferFailure::Rejected, $path);
    }

    public function receive(string $localFile, string $path, int $size): void
    {
        $this->assertWritable($path);

        $target = $this->absolute($path);
        $temp = $this->absolute($this->temporaryName($path));

        try {
            $this->copyStream($localFile, $temp);
        } catch (Throwable $e) {
            @unlink($temp);

            throw TransferFailed::because(TransferFailure::Unwritable, $path, $e);
        }

        clearstatcache(true, $temp);

        if ((int) @filesize($temp) !== $size) {
            @unlink($temp);

            throw TransferFailed::because(TransferFailure::Incomplete, $path);
        }

        $this->placeWithoutReplacing($temp, $target, $path);
    }

    public function delete(string $path): void
    {
        $this->assertWritable($path);

        $absolute = $this->absolute($path);

        if (is_link($absolute) || ! is_file($absolute) || ! @unlink($absolute)) {
            throw TransferFailed::because(TransferFailure::Unwritable, $path);
        }
    }

    public function read(string $path): ?string
    {
        $absolute = $this->absolute($path);

        if (! is_file($absolute) || is_link($absolute)) {
            return null;
        }

        $contents = @file_get_contents($absolute);

        return $contents === false ? null : $contents;
    }

    public function replace(string $path, string $contents): void
    {
        $this->assertWritable($path);

        $temp = $this->absolute($this->temporaryName($path));

        if (@file_put_contents($temp, $contents) !== strlen($contents) || ! @rename($temp, $this->absolute($path))) {
            @unlink($temp);

            throw TransferFailed::because(TransferFailure::Unwritable, $path);
        }
    }

    /** @throws TransferFailed */
    protected function makeDirectory(string $directory): void
    {
        if ($directory === '') {
            return;
        }

        $absolute = $this->absolute($directory);

        if (! is_dir($absolute) && ! @mkdir($absolute, 0775, true) && ! is_dir($absolute)) {
            throw TransferFailed::because(TransferFailure::Unwritable, $directory);
        }
    }

    /**
     * Rename a finished file into place, refusing if the name was taken meanwhile.
     *
     * link() rather than rename(): rename replaces whatever is there without a
     * word, link refuses. Filesystems without hard links — FAT on a drive,
     * some network mounts — fall back to a check and a rename.
     *
     * @throws TransferFailed
     */
    protected function placeWithoutReplacing(string $temp, string $target, string $path): void
    {
        if (@link($temp, $target)) {
            @unlink($temp);

            return;
        }

        if (file_exists($target) || is_link($target)) {
            @unlink($temp);

            throw TransferFailed::because(TransferFailure::Exists, $path);
        }

        if (! @rename($temp, $target)) {
            @unlink($temp);

            throw TransferFailed::because(TransferFailure::Unwritable, $path);
        }
    }

    /** @throws TransferFailed */
    protected function assertWritable(string $path): void
    {
        if (! $this->writable()) {
            throw TransferFailed::because(TransferFailure::Unwritable, $path);
        }
    }

    /** A dot-name beside the file, which Samba hides from the share while it is written. */
    protected function temporaryName(string $path): string
    {
        return $this->join(dirname($path) === '.' ? '' : dirname($path), '.'.basename($path).'.retrobite-part');
    }

    protected function join(string $directory, string $name): string
    {
        return $directory === '' ? $name : rtrim($directory, '/').'/'.$name;
    }

    private function copyStream(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = fopen($target, 'wb');

        try {
            if ($in === false || $out === false || stream_copy_to_stream($in, $out) === false) {
                throw TransferFailed::because(TransferFailure::Unwritable, $target);
            }
        } finally {
            is_resource($in) && fclose($in);
            is_resource($out) && fclose($out);
        }
    }
}
