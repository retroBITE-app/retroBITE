<?php

declare(strict_types=1);

namespace App\Transfers\Endpoints;

use App\Exceptions\TransferFailed;

/**
 * Somewhere FileTransferJob reads from or writes to: a console's folder in the
 * library, the upload staging directory, the artwork disk, a network share.
 *
 * Every path is relative to the endpoint's root and admitted by the endpoint
 * itself, so a location can never reach outside the place it names. Every
 * refusal is a {@see TransferFailed}.
 */
interface Endpoint
{
    /**
     * The absolute path of a plain, readable file here. Sources are local.
     *
     * @throws TransferFailed
     */
    public function localFile(string $path): string;

    /**
     * How big what is at the path is: null when nothing is, -1 when something
     * that is not a plain file is.
     *
     * @throws TransferFailed
     */
    public function sizeOf(string $path): ?int;

    /** Free bytes, or null when this endpoint cannot say. */
    public function freeBytes(): ?int;

    /**
     * Make a folder, and prove a file can be written in it.
     *
     * @param  string  $directory  '' for the root
     *
     * @throws TransferFailed
     */
    public function prepare(string $directory): void;

    /** Whether a file on the other endpoint can be moved here by a rename. */
    public function canAdopt(Endpoint $from): bool;

    /**
     * Move a file from the other endpoint here by a rename, never over anything.
     *
     * @throws TransferFailed
     */
    public function adopt(Endpoint $from, string $fromPath, string $path): void;

    /**
     * Copy a local file here: under a temporary name, checked for size, then
     * renamed into place — and never over anything.
     *
     * @throws TransferFailed
     */
    public function receive(string $localFile, string $path, int $size): void;

    /** @throws TransferFailed */
    public function delete(string $path): void;

    /**
     * A small file's contents, or null when there is none.
     *
     * @throws TransferFailed
     */
    public function read(string $path): ?string;

    /**
     * Write a small file, replacing what is there, and making its folder
     * when there is none. The one write that may replace anything, for a
     * target's own game list.
     *
     * @throws TransferFailed
     */
    public function replace(string $path, string $contents): void;
}
