<?php

declare(strict_types=1);

namespace App\Support\Layouts;

/**
 * Open PS2 Loader's drive layout.
 *
 * Games sit directly in DVD/ and CD/, one directory per disc type, and the rest
 * of the drive is OPL's own: artwork in ART/, per-game config in CFG/, virtual
 * memory cards in VMC/, themes in THM/. Reading those as games is how a library
 * of eleven titles turns into a hundred.
 *
 * Not recursive, because OPL is not: a file one level below DVD/ is not a game
 * OPL will ever launch.
 *
 * This class says nothing about the SLES_503.86 prefix those filenames carry.
 * That is a PlayStation 2 serial wearing an OPL convention, so it belongs to
 * neither side alone and lives on App\Tools\ConsoleTool\PS2 instead.
 */
final class OplLayout extends ConsoleLayout
{
    public function key(): string
    {
        return 'opl';
    }

    public function label(): string
    {
        return __('Open PS2 Loader');
    }

    public function description(): string
    {
        return __('Games in DVD/ and CD/, with OPL\'s ART, CFG, VMC and THM folders beside them.');
    }

    /** @return string[] */
    public function gameDirectories(): array
    {
        return ['DVD', 'CD'];
    }

    /** @return string[] */
    public function ignoredDirectories(): array
    {
        return ['ART', 'CFG', 'VMC', 'THM', 'APPS', 'CHT', 'LNG'];
    }

    /**
     * The drive OPL expects, as far as it is worth building for somebody.
     *
     * DVD and CD are where games go, ART and CFG are what the exports write
     * into, VMC holds virtual memory cards and THM themes. APPS, CHT and LNG
     * are left out although they are ignored above: OPL makes those the day
     * somebody runs homebrew, loads cheats or installs a translation, and six
     * empty directories already say enough about how a drive is arranged.
     *
     * @return string[]
     */
    public function scaffold(): array
    {
        return ['DVD', 'CD', 'ART', 'CFG', 'VMC', 'THM'];
    }

    public function recursive(): bool
    {
        return false;
    }
}
