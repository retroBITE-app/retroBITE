<?php

declare(strict_types=1);

namespace App\Jobs\RetroAchievements;

use App\Models\Game;
use App\Services\RetroAchievementsMatcher;
use App\Support\RetroAchievements\IdentifyOutcome;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Work out which achievement set a game is.
 *
 * Costs no request: the answer is a lookup in the hash index that the nightly
 * sync already downloaded. What it can cost is a hash, and that goes away to
 * its own queue and comes back here when it has something to look up.
 */
class IdentifyGame implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    public int $uniqueFor = 120;

    /**
     * @param  bool  $afterHash  set on the run that follows a hash attempt, so a
     *                           hash that still is not there ends the attempt
     *                           instead of chaining another one
     */
    public function __construct(
        public readonly int $gameId,
        public readonly bool $afterHash = false,
    ) {
        $this->onQueue('ra');
    }

    public function uniqueId(): string
    {
        return 'ra-identify:'.$this->gameId;
    }

    /**
     * Queue identification for every game on a console still worth asking about.
     *
     * Unlike the ScreenScraper equivalent this includes the ones that came
     * back with nothing, because there is no scarce allowance to protect and
     * sets appear all the time.
     *
     * @return int how many were queued
     */
    public static function queueAwaiting(?string $console = null, int $limit = 0): int
    {
        $query = Game::query()->awaitingRetroAchievements();

        if ($console !== null) {
            $query->forConsole($console);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $ids = $query->pluck('games.id');

        foreach ($ids as $id) {
            self::dispatch($id);
        }

        return $ids->count();
    }

    public function handle(RetroAchievementsMatcher $matcher): void
    {
        $game = Game::find($this->gameId);

        if ($game === null) {
            return;
        }

        $result = $matcher->identify($game);

        if ($result->outcome === IdentifyOutcome::NeedsHash) {
            if ($this->afterHash) {
                // HashGame ran and there is still no usable hash — an unmounted
                // disk, most likely. Chaining another would chain for ever,
                // because the next run would reach exactly this point again.
                Log::info('RetroAchievements identification deferred; no hash after hashing.', [
                    'game' => $this->gameId,
                ]);

                return;
            }

            Bus::chain([
                new HashGame($this->gameId),
                new self($this->gameId, afterHash: true),
            ])->dispatch();

            return;
        }

        if ($result->outcome === IdentifyOutcome::Matched && $result->raGameId !== null) {
            SyncSet::dispatch($result->raGameId);
        }
    }
}
