<?php

declare(strict_types=1);

namespace App\Transfers\Endpoints;

use App\Enums\TransferFailure;
use App\Exceptions\TransferFailed;
use App\Models\Destination;
use App\Transfers\Smb\RemoteShare;
use App\Transfers\Smb\ShareClient;
use Illuminate\Support\Str;

/**
 * A saved network share, below its folder. A destination only: nothing is
 * read from another machine into the library.
 */
final class ShareEndpoint implements Endpoint
{
    private ?RemoteShare $opened = null;

    public function __construct(
        public readonly Destination $destination,
        private readonly ShareClient $client,
    ) {}

    public function localFile(string $path): string
    {
        throw TransferFailed::because(TransferFailure::Rejected, $path);
    }

    public function sizeOf(string $path): ?int
    {
        return $this->share()->size($this->remote($path));
    }

    /**
     * The names of what is in a folder of the destination.
     *
     * @return list<string>
     *
     * @throws TransferFailed
     */
    public function namesIn(string $directory): array
    {
        return $this->share()->names($this->remote($directory === '' ? null : $directory));
    }

    public function freeBytes(): ?int
    {
        // smbclient can say, but only by a `du` whose output differs between
        // servers. A full share fails the copy instead, which is undone.
        return null;
    }

    public function prepare(string $directory): void
    {
        $folder = $this->remote($directory === '' ? null : $directory);

        if ($folder !== '') {
            $this->share()->makeDirectory($folder);
        }

        $probe = ($folder === '' ? '' : $folder.'/').'.retrobite-probe-'.Str::random(8);

        try {
            $this->share()->write($probe, 'retrobite');
        } catch (TransferFailed $e) {
            throw $e->reason === TransferFailure::Rejected
                ? TransferFailed::because(TransferFailure::Unwritable, $directory, $e)
                : $e;
        }

        $this->share()->delete($probe);
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
        $target = $this->remote($path);
        $temp = (dirname($target) === '.' ? '' : dirname($target).'/').'.'.basename($target).'.retrobite-part';

        try {
            $this->share()->put($localFile, $temp);

            if ($this->share()->size($temp) !== $size) {
                throw TransferFailed::because(TransferFailure::Incomplete, $path);
            }

            $this->share()->rename($temp, $target);
        } catch (TransferFailed $e) {
            $this->quietlyDelete($temp);

            throw $e;
        }
    }

    public function delete(string $path): void
    {
        $this->share()->delete($this->remote($path));
    }

    public function read(string $path): ?string
    {
        return $this->share()->get($this->remote($path));
    }

    public function replace(string $path, string $contents): void
    {
        $target = $this->remote($path);
        $temp = (dirname($target) === '.' ? '' : dirname($target).'/').'.'.basename($target).'.retrobite-part';

        // A list can live where no game file went, as ES-DE's does.
        if (dirname($target) !== '.') {
            $this->share()->makeDirectory(dirname($target));
        }

        $this->share()->write($temp, $contents);

        if ($this->share()->size($target) !== null) {
            $this->share()->delete($target);
        }

        $this->share()->rename($temp, $target);
    }

    /**
     * The path inside the share: the destination's folder, then this one.
     *
     * @throws TransferFailed
     */
    private function remote(?string $path): string
    {
        $parts = array_filter([trim($this->destination->folder, '/'), $path], fn (?string $part): bool => $part !== null && $part !== '');
        $joined = implode('/', $parts);

        if (Str::contains($joined, ['\\', "\0"]) || in_array('..', explode('/', $joined), true) || in_array('.', explode('/', $joined), true)) {
            throw TransferFailed::because(TransferFailure::Rejected, (string) $path);
        }

        return $joined;
    }

    /** @throws TransferFailed */
    private function share(): RemoteShare
    {
        return $this->opened ??= $this->client->open($this->destination);
    }

    private function quietlyDelete(string $path): void
    {
        try {
            if ($this->share()->size($path) !== null) {
                $this->share()->delete($path);
            }
        } catch (TransferFailed) {
            // The copy is failing already; a leftover dot-file is the lesser problem.
        }
    }
}
