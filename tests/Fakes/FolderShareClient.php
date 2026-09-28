<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Enums\TransferFailure;
use App\Exceptions\TransferFailed;
use App\Models\Destination;
use App\Transfers\Smb\RemoteShare;
use App\Transfers\Smb\ShareClient;
use Illuminate\Support\Facades\File;

/**
 * Network shares as folders on disk: {root}/{host}/{share}.
 *
 * A host with no folder does not answer; `readonly` as a share name refuses
 * every write, the way a share mounted read-only does.
 */
final class FolderShareClient implements ShareClient
{
    /** @var list<string> every remote write, in order, for tests that care what was written */
    public array $writes = [];

    /** A path whose upload fails as if the connection dropped, to see a transfer undone. */
    public ?string $failPutOn = null;

    public function __construct(public readonly string $root) {}

    public function open(Destination $destination): RemoteShare
    {
        $folder = $this->root.'/'.$destination->host;

        if (! is_dir($folder)) {
            throw TransferFailed::because(TransferFailure::Unreachable, $destination->host);
        }

        if (! is_dir($folder.'/'.$destination->share)) {
            throw TransferFailed::because(TransferFailure::Rejected, $destination->share);
        }

        return new FolderShare($folder.'/'.$destination->share, $destination->share === 'readonly', $this);
    }

    public function shares(string $host, ?string $username, ?string $password): array
    {
        if (! is_dir($this->root.'/'.$host)) {
            throw TransferFailed::because(TransferFailure::Unreachable, $host);
        }

        return collect(File::directories($this->root.'/'.$host))->map(fn (string $dir): string => basename($dir))->sort()->values()->all();
    }
}
