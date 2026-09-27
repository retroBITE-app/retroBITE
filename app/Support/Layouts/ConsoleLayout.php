<?php

declare(strict_types=1);

namespace App\Support\Layouts;

/**
 * How a console's folder is arranged on disk.
 *
 * A layout describes structure and nothing else: which subfolders hold games,
 * which are support directories that are not games, and how deep to look. It
 * deliberately knows nothing about any particular console — a serial prefix in
 * a filename belongs to a console and a loader together, and lives on that
 * console's toolbox instead. See App\Tools\ConsoleTools::titleFor().
 *
 * Pure by design: no config, no filesystem, no database. Every path it is given
 * is relative to the console's own root, not to the library root.
 */
abstract class ConsoleLayout
{
    /** The key stored against a console, e.g. 'opl'. */
    abstract public function key(): string;

    /** Name for the layout picker. */
    abstract public function label(): string;

    /** One sentence saying how to recognise this layout, for the picker. */
    abstract public function description(): string;

    /**
     * Subdirectories of the console root that hold games.
     *
     * An empty string means the root itself.
     *
     * @return string[]
     */
    abstract public function gameDirectories(): array;

    /**
     * Directory names that are never games, matched at any depth.
     *
     * @return string[]
     */
    abstract public function ignoredDirectories(): array;

    /**
     * Directories this layout expects to find, relative to the console's folder.
     *
     * Distinct from ignoredDirectories(), which says what never counts as a
     * game. These two overlap without being the same list: OPL's ART/ is in
     * both, because it should exist and is not a game; APPS/ is in the second
     * only, because OPL makes it when somebody runs homebrew and an empty one
     * explains nothing.
     *
     * Empty here, because a layout that imposes no structure has nothing to
     * make, and making folders would be imposing some.
     *
     * @return string[]
     */
    public function scaffold(): array
    {
        return [];
    }

    /**
     * Whether each game lives in a folder of its own, which the uploader and
     * the organizer then make. False for every layout that files games loose.
     */
    public function perGameFolders(): bool
    {
        return false;
    }

    /**
     * Whether games may sit below a game directory rather than directly in it.
     *
     * True for the layouts that impose no structure. A loader that reads one
     * flat directory says false, and a file one level deeper is not its game.
     */
    public function recursive(): bool
    {
        return true;
    }

    /**
     * Whether a path relative to the console root is in this layout's game area.
     *
     * Directory names are compared case-insensitively: the same OPL drive turns
     * up with DVD/ on one machine and dvd/ on another, and neither is wrong.
     */
    public function accepts(string $relative): bool
    {
        $segments = array_values(array_filter(
            explode('/', trim($relative, '/')),
            function (string $segment): bool {
                return $segment !== '';
            },
        ));

        if ($segments === []) {
            return false;
        }

        // Everything but the filename.
        $directories = array_map('strtolower', array_slice($segments, 0, -1));
        $ignored = array_map('strtolower', $this->ignoredDirectories());

        if (array_intersect($directories, $ignored) !== []) {
            return false;
        }

        foreach ($this->gameDirectories() as $directory) {
            if ($this->isUnder($directories, $directory)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A placeholder title for a file, before the provider has been asked.
     *
     * The filename without its extension, which is what every layout that has
     * no convention of its own should say.
     */
    public function titleFor(string $relative): string
    {
        return pathinfo($relative, PATHINFO_FILENAME);
    }

    /**
     * Whether those directories sit under one game directory.
     *
     * @param  string[]  $directories  lowercased, filename already dropped
     */
    private function isUnder(array $directories, string $gameDirectory): bool
    {
        $wanted = strtolower(trim($gameDirectory, '/'));

        if ($wanted === '') {
            return $this->recursive() || $directories === [];
        }

        if (($directories[0] ?? null) !== $wanted) {
            return false;
        }

        return $this->recursive() || count($directories) === 1;
    }
}
