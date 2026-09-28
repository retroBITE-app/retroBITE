<?php

declare(strict_types=1);

namespace App\Events;

use App\Support\LiveUpdates;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Something the sidebar shows has changed: the queues, the provider's
 * allowance, or the space on the disk — or a search the destinations page
 * started has answered.
 *
 * A signal, not a copy of the new state. The component that hears it reads
 * its own figures again, exactly as its poll used to, so there is one way for
 * it to get them and nothing for the two to disagree about.
 *
 * Broadcast now rather than queued: queued, it would wait behind the very
 * scans and downloads it is reporting on. Sent through {@see LiveUpdates}.
 */
final class SystemUpdated implements ShouldBroadcastNow
{
    public const ACTIVITY = 'activity';

    public const QUOTA = 'quota';

    public const STORAGE = 'storage';

    /** A search for network shares, or a listing of one host's shares, has answered. */
    public const DISCOVERY = 'discovery';

    /** A console being sent to a share has moved on: a game copied, or the list written. */
    public const TRANSFER = 'transfer';

    public function __construct(public readonly string $what) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('system');
    }

    /** @return array{what: string} */
    public function broadcastWith(): array
    {
        return ['what' => $this->what];
    }
}
