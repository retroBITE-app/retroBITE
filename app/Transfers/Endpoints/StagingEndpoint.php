<?php

declare(strict_types=1);

namespace App\Transfers\Endpoints;

use App\Enums\TransferFailure;
use App\Exceptions\LibraryPathException;
use App\Exceptions\TransferFailed;
use App\Support\LibraryPath;
use Illuminate\Support\Str;

/**
 * Where uploads are assembled, and conversions written. A source only,
 * and only for plain files directly inside it or inside one folder of it — a
 * conversion writes into a folder of its own, because a cue sheet names its
 * bin and both have to carry their final names. Either leaves by a move into
 * a console's folder, which {@see LibraryEndpoint::adopt()} makes a rename.
 */
final class StagingEndpoint extends LocalEndpoint
{
    public function __construct(private readonly LibraryPath $paths) {}

    public function delete(string $path): void
    {
        $absolute = $this->absolute($path);

        if (is_file($absolute) && ! is_link($absolute) && ! @unlink($absolute)) {
            throw TransferFailed::because(TransferFailure::Unwritable, $path);
        }
    }

    protected function absolute(string $path): string
    {
        $segments = explode('/', $path);

        if (count($segments) > 2 || Str::contains($path, ['\\', "\0"]) || array_intersect($segments, ['', '.', '..']) !== []) {
            throw TransferFailed::because(TransferFailure::Rejected, $path);
        }

        try {
            return $this->paths->stagingDirectory().'/'.$path;
        } catch (LibraryPathException $e) {
            throw TransferFailed::because(TransferFailure::SourceMissing, $path, $e);
        }
    }

    protected function writable(): bool
    {
        return false;
    }

    protected function root(): string
    {
        return (string) config('settings.games_path');
    }
}
