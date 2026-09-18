<?php

namespace App\Exceptions;

use RuntimeException;

final class DocPathException extends RuntimeException
{
    /** The grammar refused the shape: a dot segment, an empty name, an extension we do not write. */
    public const MALFORMED = 'doc_path_malformed';

    /** The path resolved, but landed outside the docs root. */
    public const OUTSIDE_ROOT = 'doc_path_outside_root';

    /** A symlink stood somewhere on the way. The docs tree can sit on a mount other processes write to. */
    public const SYMLINK = 'doc_path_symlink';

    /** Not even the nearest existing ancestor could be resolved — usually a missing docs root. */
    public const UNRESOLVABLE = 'doc_path_unresolvable';

    private function __construct(
        public readonly string $path,
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The path does not match the grammar for its kind.
     */
    public static function malformed(string $path): self
    {
        return new self($path, self::MALFORMED, "Malformed docs path: {$path}");
    }

    /**
     * The path resolved outside the docs root.
     */
    public static function outsideRoot(string $path): self
    {
        return new self($path, self::OUTSIDE_ROOT, "Docs path lives outside the docs root: {$path}");
    }

    /**
     * A component of the path is a symbolic link.
     */
    public static function symlink(string $path): self
    {
        return new self($path, self::SYMLINK, "Docs path crosses a symlink: {$path}");
    }

    /**
     * Neither the path nor any ancestor of it could be resolved on disk.
     */
    public static function unresolvable(string $path): self
    {
        return new self($path, self::UNRESOLVABLE, "Docs path could not be resolved: {$path}");
    }
}
