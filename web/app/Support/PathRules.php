<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one definition of what a console subfolder and an upload id may look like.
 * Three divergent copies of these regexes previously let mkdir create paths that
 * upload refused, and vice versa.
 */
final class PathRules
{
    /** One path segment. Deliberately conservative: no dots, so no traversal. */
    private const SEGMENT = '[a-zA-Z0-9_\-]+';

    /** Lowercase UUID v4 as produced by crypto.randomUUID(). */
    private const UPLOAD_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * Is this a valid non-empty subfolder, possibly nested?
     */
    public static function isSubfolder(string $subfolder): bool
    {
        $pattern = '/^' . self::SEGMENT . '(\/' . self::SEGMENT . ')*$/';

        return preg_match($pattern, $subfolder) === 1;
    }

    /**
     * As isSubfolder(), but '' is accepted and means the console root.
     */
    public static function isSubfolderOrRoot(string $subfolder): bool
    {
        return $subfolder === '' || self::isSubfolder($subfolder);
    }

    /**
     * Is this a well-formed upload id? Used both to validate incoming chunks and to
     * decide which temp directories the sweeper may delete.
     */
    public static function isUploadId(string $uploadId): bool
    {
        return preg_match(self::UPLOAD_ID, $uploadId) === 1;
    }

    /**
     * Strip the surrounding slashes callers may send, e.g. "/BIOS/" -> "BIOS".
     */
    public static function normalizeSubfolder(string $subfolder): string
    {
        return trim($subfolder, '/');
    }
}
