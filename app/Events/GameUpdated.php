<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Work on one game has finished — or failed, which a page waiting on it
 * needs to hear just as much.
 *
 * $what says which wait it ends. On the game's own channel, so a game page
 * hears about its game and nobody else's. A signal only, like
 * {@see SystemUpdated}: the page runs the check it already has.
 */
final class GameUpdated implements ShouldBroadcastNow
{
    /** A file's checksums are in. The manual identify form was waiting on them. */
    public const HASHED = 'hashed';

    /** The provider has answered, whatever it said. */
    public const IDENTIFIED = 'identified';

    public const ARTWORK = 'artwork';

    public const RATING = 'rating';

    /** A transfer to a network share moved on: a file copied, or the whole of it done or failed. */
    public const TRANSFER = 'transfer';

    /** A job for this game gave up. Every wait on the page checks again. */
    public const FAILED = 'failed';

    public function __construct(
        public readonly int $gameId,
        public readonly string $what,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('games.'.$this->gameId);
    }

    /** @return array{what: string} */
    public function broadcastWith(): array
    {
        return ['what' => $this->what];
    }
}
