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

    /** Every file the game needs on the drive, and where the game list goes. */
    public function plan(Game $game): TransferPlan;

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
