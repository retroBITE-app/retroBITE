<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Models\Game;

/**
 * A system a drive can be prepared for: its folder layout and its game list.
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

    /**
     * What the drive should be told before it is written, when the front-end
     * needs a step of its own to see what arrives; null for none.
     */
    public function hint(): ?string;

    /**
     * The folders that mark where this layout starts on a drive, from the
     * root the plan's paths are relative to: roms for Batocera, and
     * batocera/roms where Batocera keeps its tree in a folder of its own. The
     * first is what a drive without any of them is given.
     *
     * @return non-empty-list<string>
     */
    public function roots(): array;

    /** Every file the game needs on the drive, and where the game list goes. */
    public function plan(Game $game): TransferPlan;

    /** Where a console's game list goes, from the drive's root. */
    public function gamelistFor(string $console): string;

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
     *                          than replacing it
     */
    public function mergeGamelist(?string $existing, Game ...$games): string;
}
