<?php

declare(strict_types=1);

namespace App\Transfers\Endpoints;

use App\Enums\TransferFailure;
use App\Exceptions\TransferFailed;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Downloaded artwork, read so a cover can go out with its game. A source
 * only: MediaLibrary owns every write to this disk.
 */
final class MediaEndpoint extends LocalEndpoint
{
    protected function absolute(string $path): string
    {
        $wellFormed = $path !== ''
            && ! Str::startsWith($path, '/')
            && ! Str::contains($path, ['\\', "\0"])
            && collect(explode('/', $path))->every(fn (string $segment): bool => ! in_array($segment, ['', '.', '..'], true));

        if (! $wellFormed) {
            throw TransferFailed::because(TransferFailure::Rejected, $path);
        }

        return Storage::disk('media')->path($path);
    }

    protected function writable(): bool
    {
        return false;
    }

    protected function root(): string
    {
        return Storage::disk('media')->path('');
    }
}
