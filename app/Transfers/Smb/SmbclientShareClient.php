<?php

declare(strict_types=1);

namespace App\Transfers\Smb;

use App\Models\Destination;
use Icewind\SMB\BasicAuth;
use Icewind\SMB\IAuth;
use Icewind\SMB\IServer;
use Icewind\SMB\IShare;
use Icewind\SMB\Options;
use Icewind\SMB\ServerFactory;
use Throwable;

/**
 * Other machines' shares through libsmbclient, by way of icewind/smb.
 *
 * Rather than a CIFS mount: a mount needs SYS_ADMIN in the web container and
 * one mount per destination, and libsmbclient needs neither. It is the
 * `smbclient` PHP extension in the image, over `samba-client`'s library;
 * icewind picks it on its own when the extension is loaded. Without it,
 * icewind drives the `smbclient` command instead, whose text it misreads
 * after a long directory listing — see docs/adr/0004.
 */
final class SmbclientShareClient implements ShareClient
{
    /** Seconds before a host that does not answer is given up on. */
    private const TIMEOUT = 10;

    public function open(Destination $destination): RemoteShare
    {
        try {
            $share = $this->server($destination->host, $destination->username, $destination->password)
                ->getShare($destination->share);
        } catch (Throwable $e) {
            throw SmbclientShare::translate($e, $destination->share);
        }

        return new SmbclientShare($share);
    }

    public function shares(string $host, ?string $username, ?string $password): array
    {
        try {
            $shares = $this->server($host, $username, $password)->listShares();
        } catch (Throwable $e) {
            throw SmbclientShare::translate($e, $host);
        }

        // Disk shares only, already; the hidden admin and IPC shares end in $.
        return array_values(collect($shares)
            ->map(fn (IShare $share): string => $share->getName())
            ->reject(fn (string $name): bool => str_ends_with($name, '$'))
            ->all());
    }

    private function server(string $host, ?string $username, ?string $password): IServer
    {
        $options = new Options;
        $options->setTimeout(self::TIMEOUT);

        return (new ServerFactory($options))->createServer($host, $this->auth($username, $password));
    }

    private function auth(?string $username, ?string $password): IAuth
    {
        if ($username === null || $username === '') {
            return new GuestAuth;
        }

        // DOMAIN\user, as Windows writes it, or a bare name.
        [$workgroup, $user] = str_contains($username, '\\') ? explode('\\', $username, 2) : [null, $username];

        return new BasicAuth($user, $workgroup, (string) $password);
    }
}
