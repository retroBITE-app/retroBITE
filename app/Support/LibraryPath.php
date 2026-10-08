<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\LibraryPathException;
use App\Models\ConsoleSourceFolder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The one gate every write into somebody's game library goes through.
 *
 * Almost nothing here writes: the scanner reads, the matcher writes to the
 * database, and artwork lives on a disk of its own. The exceptions are the
 * loader exports — Open PS2 Loader's CFG/ and ART/ — which put files back
 * beside somebody's ROMs, and ROM uploads, which put the ROMs themselves
 * there. Those are worth one gate rather than a `mkdir`
 * and an `unlink` scattered across components. The previous build funnelled
 * this through a single check for the same reason.
 *
 * Every path in and out is relative to the library root, which is what
 * game_files.path stores.
 *
 * Anchored to config('settings.games_path') rather than to the `games` disk.
 * The two hold the same value, but they are two declarations of it, and the
 * scanner already reads through the config one — a library pointed somewhere
 * else would otherwise be read from one place and written to another.
 */
final class LibraryPath
{
    /**
     * Where uploads are assembled before they move into a console's folder.
     *
     * Under the library root rather than storage/, so the final move is a
     * rename on one filesystem instead of a copy of a multi-gigabyte image
     * across two. The leading dot keeps it off the share: Samba hides dot
     * files by default.
     */
    public const STAGING = '.retrobite-uploads';

    private readonly string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? (string) config('settings.games_path'), '/');
    }

    /** The library root, absolute and without a trailing slash: what every relative path hangs off. */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * A library-root path as the console's folder sees it, or null when it is outside it.
     *
     * @param  string  $path  relative to the library root, as game_files.path stores it
     */
    public function consoleRelative(Console $console, string $path): ?string
    {
        $root = ConsoleSourceFolder::pathFor($console);

        if ($root === null || ! Str::startsWith($path, $root.'/')) {
            return null;
        }

        return Str::after($path, $root.'/');
    }

    /**
     * Admit a path inside a console's own folder.
     *
     * Returns it relative to the library root, ready for the `games` disk.
     *
     * @param  string  $relative  relative to the CONSOLE's folder, e.g. "CFG/SLES_503.86.cfg"
     *
     * @throws LibraryPathException
     */
    public function within(Console $console, string $relative): string
    {
        $consoleRoot = ConsoleSourceFolder::pathFor($console);

        if ($consoleRoot === null || $consoleRoot === '') {
            throw LibraryPathException::unconfigured($console->key);
        }

        if (! self::isWellFormed($relative)) {
            throw LibraryPathException::malformed($relative);
        }

        $path = trim($consoleRoot, '/').'/'.trim($relative, '/');

        return $this->assertWithin($path, $consoleRoot);
    }

    /**
     * Create the folder a console's config names, under the library root.
     *
     * The one write here that is not inside a console's folder, because it is
     * the console's folder: there is nothing to be contained by yet. Returns
     * the folder relative to the library root, e.g. "ps2".
     *
     * @throws LibraryPathException
     */
    public function createConsoleRoot(Console $console): string
    {
        $folder = trim($console->folder, '/');

        // One plain segment, as all 135 console files declare. `folder` is
        // deliberately absent from config/console_overrides.php and so cannot
        // be edited at runtime — checked anyway, because this is the gate and
        // a gate that trusts its input is decoration.
        if ($folder === '' || ! self::isWellFormed($folder) || Str::contains($folder, '/')) {
            throw LibraryPathException::malformed($console->folder);
        }

        if (! is_dir($this->root)) {
            throw LibraryPathException::rootMissing($this->root);
        }

        $path = $this->root.'/'.$folder;

        // A file sitting where the folder should be. Not something to write
        // around, and not something to delete either.
        if (file_exists($path) && ! is_dir($path)) {
            throw LibraryPathException::notCreated($folder);
        }

        File::ensureDirectoryExists($path);

        // Confirmed rather than assumed: mkdir fails by returning false on a
        // read-only mount or under the wrong owner, and the caller has to hear
        // about that instead of carrying on to a scan that finds nothing.
        if (! is_dir($path)) {
            throw LibraryPathException::notCreated($folder);
        }

        return $folder;
    }

    /**
     * Make sure a directory inside a console's folder exists.
     *
     * @throws LibraryPathException
     */
    public function ensureDirectory(Console $console, string $relative): string
    {
        $path = $this->within($console, $relative);

        File::ensureDirectoryExists($this->root.'/'.$path);

        return $path;
    }

    /**
     * Whether a path inside a console's folder is already there.
     *
     * @throws LibraryPathException
     */
    public function exists(Console $console, string $relative): bool
    {
        return File::exists($this->absolute($console, $relative));
    }

    /**
     * Write a file inside a console's folder.
     *
     * @throws LibraryPathException
     */
    public function put(Console $console, string $relative, string $contents): void
    {
        File::put($this->absolute($console, $relative), $contents);
    }

    /**
     * Read a file inside a console's folder, or '' when it is not there.
     *
     * @throws LibraryPathException
     */
    public function get(Console $console, string $relative): string
    {
        $path = $this->absolute($console, $relative);

        return File::exists($path) ? (string) File::get($path) : '';
    }

    /**
     * Absolute path on disk, for the callers that cannot use the disk — GD
     * writes through a filename, not a stream.
     *
     * @throws LibraryPathException
     */
    public function absolute(Console $console, string $relative): string
    {
        return $this->root.'/'.$this->within($console, $relative);
    }

    /**
     * The staging directory, made if it is missing. Absolute.
     *
     * @throws LibraryPathException
     */
    public function stagingDirectory(): string
    {
        if (! is_dir($this->root)) {
            throw LibraryPathException::rootMissing($this->root);
        }

        $staging = $this->root.'/'.self::STAGING;

        if (is_link($staging)) {
            throw LibraryPathException::symlink(self::STAGING);
        }

        File::ensureDirectoryExists($staging);

        if (! is_dir($staging)) {
            throw LibraryPathException::notCreated(self::STAGING);
        }

        return $staging;
    }

    /**
     * Move a staged upload into a console's folder, never over anything.
     *
     * The source has to be a plain file directly inside the staging
     * directory, or inside one folder of it where a conversion writes,
     * so this cannot be turned into a way to move arbitrary files around the
     * library. Returns the new path relative to the library root.
     *
     * @param  string  $relative  relative to the CONSOLE's folder, e.g. "DVD/Game.iso"
     * @param  string  $source  absolute path of the staged file
     *
     * @throws LibraryPathException
     */
    public function moveInto(Console $console, string $relative, string $source): string
    {
        $staging = realpath($this->stagingDirectory());
        $real = realpath($source);

        $inStaging = $real !== false && (dirname($real) === $staging || dirname($real, 2) === $staging);

        if ($staging === false || ! $inStaging || is_link($source) || ! is_file((string) $real)) {
            throw LibraryPathException::outsideRoot(basename($source));
        }

        return $this->place($console, (string) $real, $relative);
    }

    /**
     * Move a file from one place in a console's folder to another.
     *
     * Both ends go through the gate, the source has to be a plain file, and
     * nothing is ever overwritten. Returns the new path relative to the
     * library root.
     *
     * @param  string  $from  relative to the CONSOLE's folder
     * @param  string  $to  relative to the CONSOLE's folder
     *
     * @throws LibraryPathException
     */
    public function relocate(Console $console, string $from, string $to): string
    {
        $source = $this->plainFile($console, $from);

        return $this->place($console, $source, $to);
    }

    /**
     * Delete one file inside a console's folder. Never a directory, never a link.
     *
     * @param  string  $relative  relative to the CONSOLE's folder
     *
     * @throws LibraryPathException
     */
    public function delete(Console $console, string $relative): void
    {
        $path = $this->plainFile($console, $relative);

        File::delete($path);

        clearstatcache(true, $path);

        if (file_exists($path)) {
            throw LibraryPathException::notDeleted($this->within($console, $relative));
        }
    }

    /**
     * The absolute path of a plain file inside a console's folder.
     *
     * @throws LibraryPathException
     */
    private function plainFile(Console $console, string $relative): string
    {
        $path = $this->absolute($console, $relative);

        if (is_link($path) || ! is_file($path)) {
            throw LibraryPathException::notAFile($this->within($console, $relative));
        }

        return $path;
    }

    /**
     * Put a file at a path inside a console's folder, never over anything.
     *
     * The tail every move shares. Returns the new path relative to the library
     * root.
     *
     * @param  string  $source  absolute path, already vetted by the caller
     * @param  string  $relative  relative to the CONSOLE's folder
     *
     * @throws LibraryPathException
     */
    private function place(Console $console, string $source, string $relative): string
    {
        $path = $this->within($console, $relative);
        $target = $this->root.'/'.$path;

        if (file_exists($target) || is_link($target)) {
            throw LibraryPathException::exists($path);
        }

        $parent = dirname($relative);

        if ($parent !== '.') {
            $this->ensureDirectory($console, $parent);
        }

        // Once more now the parent exists: a link planted while it was being
        // made is refused here rather than followed by the rename.
        $this->within($console, $relative);

        if (! File::move($source, $target)) {
            throw LibraryPathException::notMoved($path);
        }

        return $path;
    }

    /**
     * Shape only: no dot segments, no leading slash, no empty names.
     *
     * Backslashes are rejected rather than normalised. A Windows-shaped path
     * arriving here means somebody built it somewhere unexpected, and guessing
     * what they meant is how a gate stops being one.
     */
    private static function isWellFormed(string $relative): bool
    {
        if ($relative === '' || Str::startsWith($relative, '/') || Str::contains($relative, ['\\', "\0"])) {
            return false;
        }

        foreach (explode('/', trim($relative, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve the path for real and check where it landed.
     *
     * @throws LibraryPathException
     */
    private function assertWithin(string $relative, string $consoleRoot): string
    {
        $base = self::resolveIntended($this->root.'/'.trim($consoleRoot, '/'));
        $target = self::resolveIntended($this->root.'/'.$relative);

        if ($base === null || $target === null) {
            throw LibraryPathException::unresolvable($relative);
        }

        if ($target === $base || ! Str::startsWith($target.'/', $base.'/')) {
            throw LibraryPathException::outsideRoot($relative);
        }

        $this->assertNoSymlink($relative);

        return $relative;
    }

    /**
     * The real path of the nearest ancestor that exists, plus what is left.
     *
     * realpath() alone answers null for a file about to be created, which is
     * every write this gate exists for.
     */
    private static function resolveIntended(string $path): ?string
    {
        $missing = [];
        $candidate = $path;

        while (! file_exists($candidate)) {
            $parent = dirname($candidate);

            if ($parent === $candidate) {
                return null;
            }

            array_unshift($missing, basename($candidate));
            $candidate = $parent;
        }

        $real = realpath($candidate);

        if ($real === false) {
            return null;
        }

        return $missing === [] ? $real : $real.'/'.implode('/', $missing);
    }

    /**
     * Refuse a path any component of which is a symbolic link.
     *
     * The games directory is a bind mount over somebody's drive and other
     * processes write to it. A link planted inside the console's folder would
     * otherwise pass the containment check and land the write elsewhere.
     *
     * @throws LibraryPathException
     */
    private function assertNoSymlink(string $relative): void
    {
        $candidate = $this->root;

        foreach (explode('/', $relative) as $segment) {
            $candidate .= '/'.$segment;

            if (is_link($candidate)) {
                throw LibraryPathException::symlink($relative);
            }
        }
    }
}
