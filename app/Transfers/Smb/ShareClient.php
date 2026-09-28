<?php

declare(strict_types=1);

namespace App\Transfers\Smb;

use App\Exceptions\TransferFailed;
use App\Models\Destination;

/**
 * The way to other machines' shares. Bound in AppServiceProvider to
 * {@see SmbclientShareClient}, and to a folder on disk in the tests.
 */
interface ShareClient
{
    /** @throws TransferFailed */
    public function open(Destination $destination): RemoteShare;

    /**
     * The disk shares a host offers — not printers, not IPC$ or the admin shares.
     *
     * @return list<string>
     *
     * @throws TransferFailed
     */
    public function shares(string $host, ?string $username, ?string $password): array;
}
