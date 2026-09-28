<?php

declare(strict_types=1);

namespace App\Transfers\Smb;

use App\Exceptions\TransferFailed;

/**
 * One share on another machine, opened with its credentials.
 *
 * Paths are relative to the share's root. Every failure is a
 * {@see TransferFailed} whose reason says whether asking again could help.
 */
interface RemoteShare
{
    /**
     * Null when nothing is at the path, -1 when a folder is.
     *
     * @throws TransferFailed
     */
    public function size(string $path): ?int;

    /**
     * Make a folder and every missing folder above it.
     *
     * @throws TransferFailed
     */
    public function makeDirectory(string $path): void;

    /**
     * Upload a local file, replacing nothing the caller has not checked for.
     *
     * @throws TransferFailed
     */
    public function put(string $localFile, string $path): void;

    /**
     * Rename, refusing when the new name is taken.
     *
     * @throws TransferFailed
     */
    public function rename(string $from, string $to): void;

    /** @throws TransferFailed */
    public function delete(string $path): void;

    /**
     * A small file's contents, or null when there is none.
     *
     * @throws TransferFailed
     */
    public function get(string $path): ?string;

    /** @throws TransferFailed */
    public function write(string $path, string $contents): void;
}
