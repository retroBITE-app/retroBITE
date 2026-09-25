<?php

declare(strict_types=1);

namespace App\Support;

use App\Events\GameUpdated;
use App\Events\SystemUpdated;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one way the application tells open pages that something changed.
 *
 * A signal is a courtesy, never part of the work. Reverb can be down, a key
 * can be missing, or the command can be running on a host with no Reverb at
 * all; none of that may fail the identification, download or scan that sent
 * it. So every send is caught here, and a failure is logged once a minute
 * rather than once per job — a library-wide artwork run would otherwise write
 * a warning for every file.
 */
final class LiveUpdates
{
    /** @param  SystemUpdated::*  $what */
    public static function system(string $what): void
    {
        self::send(new SystemUpdated($what));
    }

    /** @param  GameUpdated::*  $what */
    public static function game(int $gameId, string $what): void
    {
        self::send(new GameUpdated($gameId, $what));
    }

    private static function send(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            if (Cache::add('live-updates.failure-logged', true, 60)) {
                Log::warning('Live update not sent; open pages will not refresh until Reverb is back.', [
                    'event' => $event::class,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }
}
