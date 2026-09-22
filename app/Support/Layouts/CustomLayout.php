<?php

declare(strict_types=1);

namespace App\Support\Layouts;

/**
 * Whatever is already there.
 *
 * Imposes nothing: every folder holds games, nothing is a support directory,
 * and a filename is its own title. This is what the scanner did before layouts
 * existed, so it is the default everywhere and choosing it changes nothing.
 */
final class CustomLayout extends ConsoleLayout
{
    public function key(): string
    {
        return 'custom';
    }

    public function label(): string
    {
        return __('However it already is');
    }

    public function description(): string
    {
        return __('Every folder is read, nothing is skipped, and filenames are taken as they are.');
    }

    /** @return string[] */
    public function gameDirectories(): array
    {
        return [''];
    }

    /** @return string[] */
    public function ignoredDirectories(): array
    {
        return [];
    }
}
