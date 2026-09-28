<?php

declare(strict_types=1);

namespace App\Transfers\Smb;

use App\Enums\TransferFailure;
use App\Exceptions\TransferFailed;
use Icewind\SMB\Exception\AccessDeniedException;
use Icewind\SMB\Exception\AlreadyExistsException;
use Icewind\SMB\Exception\AuthenticationException;
use Icewind\SMB\Exception\ConnectException;
use Icewind\SMB\Exception\ConnectionException;
use Icewind\SMB\Exception\ForbiddenException;
use Icewind\SMB\Exception\HostDownException;
use Icewind\SMB\Exception\InvalidHostException;
use Icewind\SMB\Exception\NoRouteToHostException;
use Icewind\SMB\Exception\NotFoundException;
use Icewind\SMB\Exception\OutOfSpaceException;
use Icewind\SMB\Exception\TimedOutException;
use Icewind\SMB\IShare;
use Throwable;

/** A share opened through smbclient; see {@see SmbclientShareClient}. */
final class SmbclientShare implements RemoteShare
{
    public function __construct(private readonly IShare $share) {}

    /**
     * What an icewind exception means for a transfer.
     *
     * A host that is down or slow may answer later; a wrong password or a
     * share that refuses writes will not.
     */
    public static function translate(Throwable $e, string $path): TransferFailed
    {
        $reason = match (true) {
            $e instanceof TransferFailed => $e->reason,
            $e instanceof AuthenticationException => TransferFailure::Denied,
            $e instanceof AccessDeniedException, $e instanceof ForbiddenException => TransferFailure::Unwritable,
            $e instanceof AlreadyExistsException => TransferFailure::Exists,
            $e instanceof OutOfSpaceException => TransferFailure::NoSpace,
            $e instanceof HostDownException, $e instanceof NoRouteToHostException, $e instanceof TimedOutException,
            $e instanceof InvalidHostException, $e instanceof ConnectException, $e instanceof ConnectionException => TransferFailure::Unreachable,
            default => TransferFailure::Rejected,
        };

        return $e instanceof TransferFailed ? $e : TransferFailed::because($reason, $path, $e);
    }

    public function size(string $path): ?int
    {
        try {
            $info = $this->share->stat($path);
        } catch (NotFoundException) {
            return null;
        } catch (Throwable $e) {
            throw self::translate($e, $path);
        }

        return $info->isDirectory() ? -1 : $info->getSize();
    }

    public function makeDirectory(string $path): void
    {
        $built = '';

        foreach (explode('/', trim($path, '/')) as $segment) {
            $built = $built === '' ? $segment : $built.'/'.$segment;

            if ($this->size($built) === -1) {
                continue;
            }

            $this->attempt(fn () => $this->share->mkdir($built), $built);
        }
    }

    public function put(string $localFile, string $path): void
    {
        $this->attempt(fn () => $this->share->put($localFile, $path), $path);
    }

    public function rename(string $from, string $to): void
    {
        // Samba refuses a rename onto an existing name, but not every server
        // is Samba: asked first, so a Windows share cannot replace in silence.
        if ($this->size($to) !== null) {
            throw TransferFailed::because(TransferFailure::Exists, $to);
        }

        $this->attempt(fn () => $this->share->rename($from, $to), $to);
    }

    public function delete(string $path): void
    {
        $this->attempt(fn () => $this->share->del($path), $path);
    }

    public function get(string $path): ?string
    {
        if ($this->size($path) === null) {
            return null;
        }

        $local = tempnam(sys_get_temp_dir(), 'retrobite-smb-');

        try {
            $this->attempt(fn () => $this->share->get($path, (string) $local), $path);

            $contents = @file_get_contents((string) $local);

            return $contents === false ? null : $contents;
        } finally {
            @unlink((string) $local);
        }
    }

    public function write(string $path, string $contents): void
    {
        $local = tempnam(sys_get_temp_dir(), 'retrobite-smb-');

        try {
            file_put_contents((string) $local, $contents);
            $this->put((string) $local, $path);
        } finally {
            @unlink((string) $local);
        }
    }

    /**
     * @param  callable(): mixed  $call
     *
     * @throws TransferFailed
     */
    private function attempt(callable $call, string $path): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            throw self::translate($e, $path);
        }
    }
}
