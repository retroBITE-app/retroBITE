<?php

declare(strict_types=1);

namespace App\Support\RetroAchievements;

use App\Models\GameFile;

/**
 * Enough about a file on disk to tell whether its RA hash is still good.
 *
 * Covers the file's children as well as the file itself, and that is the whole
 * reason this is a class rather than two calls to stat(). RAHasher given a
 * .cue reads the .bin tracks the sheet names; replacing a track changes the
 * hash but leaves the .cue's own size and mtime untouched, so a fingerprint
 * taken from the container alone would hand back a stale hash for ever.
 */
final class FileFingerprint
{
    private function __construct(
        public readonly int $size,
        public readonly int $mtime,
    ) {}

    /**
     * Fingerprint a file and everything it references, or null if it is gone.
     *
     * Sizes are summed and mtimes maxed: either changing is enough to mean
     * "hash this again", and neither needs to be attributable to one file.
     */
    public static function of(GameFile $file): ?self
    {
        $root = rtrim((string) config('settings.games_path'), '/');
        $paths = [$file->path, ...$file->children()->pluck('path')->all()];

        $size = 0;
        $mtime = 0;
        $seen = false;

        foreach ($paths as $relative) {
            $absolute = $root.'/'.$relative;

            if (! is_file($absolute)) {
                continue;
            }

            $seen = true;
            $size += (int) filesize($absolute);
            $mtime = max($mtime, (int) filemtime($absolute));
        }

        // The container itself missing means there is nothing to hash. One
        // absent track, though, is still worth hashing: RAHasher will say so
        // far better than a guess here would.
        return $seen ? new self($size, $mtime) : null;
    }

    public function matches(?int $size, ?int $mtime): bool
    {
        return $size !== null && $mtime !== null && $size === $this->size && $mtime === $this->mtime;
    }
}
