<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A write into the game library was refused before it happened.
 *
 * Carries the reason as a constant rather than a message to parse: callers
 * log it and show the person a fixed string, because the path in the message
 * is a container path and no business of theirs.
 */
final class LibraryPathException extends RuntimeException
{
    /** The grammar refused the shape: a dot segment, a leading slash, an empty name. */
    public const MALFORMED = 'library_path_malformed';

    /** The path resolved, but landed outside the console's own folder. */
    public const OUTSIDE_ROOT = 'library_path_outside_root';

    /** A symlink stood somewhere on the way. A games directory is usually a mount. */
    public const SYMLINK = 'library_path_symlink';

    /** The console is not in the library, so it has no folder to write into. */
    public const UNCONFIGURED = 'library_path_unconfigured';

    /** The library root itself is not there — usually GAMES_PATH is not mounted. */
    public const ROOT_MISSING = 'library_path_root_missing';

    /** The directory could not be made. A read-only mount, or the wrong owner. */
    public const NOT_CREATED = 'library_path_not_created';

    /** Neither the path nor any ancestor of it could be resolved on disk. */
    public const UNRESOLVABLE = 'library_path_unresolvable';

    /** Something is already at the target. Moves into the library never overwrite. */
    public const EXISTS = 'library_path_exists';

    /** The rename into place failed. A read-only mount, or the wrong owner. */
    public const NOT_MOVED = 'library_path_not_moved';

    private function __construct(
        public readonly string $path,
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function malformed(string $path): self
    {
        return new self($path, self::MALFORMED, "Malformed library path: {$path}");
    }

    public static function outsideRoot(string $path): self
    {
        return new self($path, self::OUTSIDE_ROOT, "Library path lives outside the console's folder: {$path}");
    }

    public static function symlink(string $path): self
    {
        return new self($path, self::SYMLINK, "Library path crosses a symlink: {$path}");
    }

    public static function unconfigured(string $console): self
    {
        return new self($console, self::UNCONFIGURED, "Console is not in the library: {$console}");
    }

    public static function unresolvable(string $path): self
    {
        return new self($path, self::UNRESOLVABLE, "Library path could not be resolved: {$path}");
    }

    /**
     * The library root is not a directory.
     *
     * Worth refusing rather than creating: a GAMES_PATH that failed to mount
     * would otherwise have the tree built on the container's own filesystem,
     * where it looks right in the interface, is invisible over the share, and
     * disappears on the next `docker compose up`.
     */
    public static function rootMissing(string $path): self
    {
        return new self($path, self::ROOT_MISSING, "Library root is not there: {$path}");
    }

    public static function notCreated(string $path): self
    {
        return new self($path, self::NOT_CREATED, "Could not create: {$path}");
    }

    public static function exists(string $path): self
    {
        return new self($path, self::EXISTS, "Something is already at: {$path}");
    }

    public static function notMoved(string $path): self
    {
        return new self($path, self::NOT_MOVED, "Could not move into: {$path}");
    }
}
