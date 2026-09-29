<?php

declare(strict_types=1);

namespace App\Conversion;

use App\Enums\FileRole;
use App\Models\GameFile;
use Illuminate\Support\Str;

/**
 * One disc of what is being converted: the file a tool is pointed at, and
 * every file that has to come along for it to read.
 *
 * A cue sheet brings its tracks; an image is on its own.
 */
final class Disc
{
    /**
     * @param  list<GameFile>  $members  the file itself first, then its tracks
     */
    public function __construct(
        public readonly GameFile $file,
        public readonly array $members,
        public readonly int $number,
    ) {}

    public function extension(): string
    {
        return Str::lower((string) $this->file->extension);
    }

    /** The file's name without its extension, which the output is named for. */
    public function stem(): string
    {
        return pathinfo($this->file->filename, PATHINFO_FILENAME);
    }

    /**
     * The one image a tool that reads raw sectors is pointed at: an image
     * itself, or the only track of a single-track sheet. Null for a sheet of
     * several tracks, which only a tool that reads the sheet can take.
     */
    public function image(): ?GameFile
    {
        if ($this->file->role !== FileRole::Sheet) {
            return $this->file;
        }

        $tracks = array_values(array_filter($this->members, function (GameFile $member): bool {
            return $member->role === FileRole::Track;
        }));

        return count($tracks) === 1 ? $tracks[0] : null;
    }

    /** What the disc takes up, from what the scanner last measured. */
    public function bytes(): int
    {
        return (int) collect($this->members)->sum('size_bytes');
    }
}
