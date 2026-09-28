<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Models\Destination;
use App\Transfers\Smb\RemoteShare;
use App\Transfers\Smb\ShareClient;
use LogicException;

/**
 * The suite's default: no test reaches a real share. The same rule as stray
 * HTTP requests and processes — a test that needs one binds FolderShareClient.
 */
final class RefusingShareClient implements ShareClient
{
    public function open(Destination $destination): RemoteShare
    {
        throw new LogicException('A test tried to open a real network share. Bind Tests\Fakes\FolderShareClient.');
    }

    public function shares(string $host, ?string $username, ?string $password): array
    {
        throw new LogicException('A test tried to list a real host\'s shares. Bind Tests\Fakes\FolderShareClient.');
    }
}
