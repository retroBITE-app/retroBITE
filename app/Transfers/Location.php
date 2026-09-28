<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Models\Destination;
use App\Support\Console;

/**
 * A place a file is, or is going: an endpoint and a path inside it.
 *
 * Plain strings, so a job carrying a list of these serialises to something a
 * person can read in the jobs table. What the endpoint key means, and whether
 * the path is admitted, is decided by {@see Endpoints} when the job runs.
 */
final class Location
{
    public function __construct(
        public readonly string $endpoint,
        /** Relative to the endpoint's root, with forward slashes. */
        public readonly string $path,
    ) {}

    /** Inside a console's folder in the library; the path relative to that folder. */
    public static function library(Console $console, string $relative): self
    {
        return new self('library:'.$console->key, $relative);
    }

    /** A finished upload, directly inside the staging directory. */
    public static function staging(string $filename): self
    {
        return new self('staging', $filename);
    }

    /** Downloaded artwork; the path as media.path stores it. */
    public static function media(string $path): self
    {
        return new self('media', $path);
    }

    /** Inside a saved network share, below its folder. */
    public static function destination(Destination $destination, string $path): self
    {
        return new self('destination:'.$destination->id, $path);
    }

    public function __toString(): string
    {
        return $this->endpoint.':'.$this->path;
    }
}
