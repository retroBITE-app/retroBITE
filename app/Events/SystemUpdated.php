<?php

declare(strict_types=1);

namespace App\Events;

use App\Support\LiveUpdates;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Something the sidebar shows has changed: the queues, the provider's
 * allowance, or the space on the disk.
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
