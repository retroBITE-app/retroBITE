<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Models\Game;
use App\Support\Console;

/**
 * A system a drive can be prepared for: its folder layout and, where it keeps
 * one, its game list.
 *
 * The server decides everything a transfer writes — which files, where, and
 * what goes in the game list — and the browser only copies. So a target is
 * all of the knowledge about one system and none of the copying.
 */
interface TransferTarget
{
    /** The key in config/transfer.php and in the URLs. */
    public function key(): string;

    /** The name shown in the interface. */
    public function label(): string;

    /** Whether this system plays the console's games at all. */
    public function supports(Console $console): bool;

    /**
     * What the drive should be told before it is written, when the front-end
     * needs a step of its own to see what arrives; null for none.
     */
    public function hint(): ?string;

    /**
     * The folders that mark where this system's tree starts on a drive, the
     * first found winning — roms/ for Batocera, at the top or under
     * batocera/. The first is what a drive without any of them is given.
     * None: the drive's own root is the tree's, whatever it holds.
     *
     * @return list<string>
     */
    public function root(): array;

    /**
     * Every file the game needs on the drive, and where the game list goes.
     *
     * @throws TransferRejected when the game cannot be laid out this way
     */
    public function plan(Game $game): TransferPlan;

    /** Where a console's game list goes, from the drive's root; null for a system that keeps none. */
    public function gamelistFor(string $console): ?string;

    /**
     * The files this system wants beside a game's that the library does not
     * hold — OPL's config and art — by where they go on the drive. Made when
     * they are written, never stored; one already on the drive is left alone,
     * since somebody may have tuned it there.
     *
     * @return list<string>
     */
    public function extras(Game $game): array;

    /** One of those files' bytes, or null for a destination that is not one of them. */
    public function extra(Game $game, string $destination): ?string;

    /**
     * The game list with these games' entries added or brought up to date —
     * one game from its page, a console's worth when a console is sent. All
     * of them share one list: they are one console's games.
     *
     * Everyone else's entries are left exactly as they were: the drive is its
     * owner's, like the library.
     *
     * @param  string|null  $existing  what is on the drive now, or null for none
     *
     * @throws TransferRejected when the existing list cannot be read, rather
     *                          than replacing it, or the system keeps none
     */
    public function mergeGamelist(?string $existing, Game ...$games): string;
}
