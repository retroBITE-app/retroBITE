<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RetroAchievementsStatus;
use App\Jobs\RetroAchievements\IdentifyGame;
use App\Models\AppSetting;
use App\Models\Game;
use App\Models\GameFile;
use App\Services\RetroAchievementsHasher;
use Illuminate\Console\Command;

/**
 * Give the games RAHasher could not hash another go, once per RAHasher build.
 *
 * A failure is kept against the file it failed on, and the same file fails
 * the same way — until RAHasher learns to read it, as it did CSO and ZSO.
 * Run by the entrypoint at every boot, so a new image that ships a different
 * build retries them by itself and nobody has to know this command exists.
 * Only queues: the workers start after it and do the reading.
 */
class RetryFailedHashes extends Command
{
    protected $signature = 'retrobite:ra:retry-failed-hashes
                            {--force : Retry even when RAHasher has not changed.}';

    protected $description = 'Retry the games RAHasher could not hash, when RAHasher has changed';

    /** Retry once per new build; a missing binary changes nothing and records nothing. */
    public function handle(RetroAchievementsHasher $hasher): int
    {
        $build = $hasher->build();

        if ($build === null) {
            // A broken deployment says nothing about the files; retrying now
            // would only fail them all again.
            $this->warn('RAHasher is not installed; nothing retried.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && AppSetting::get(AppSetting::RA_HASHER_BUILD) === $build) {
            $this->info('RAHasher unchanged; nothing to retry.');

            return self::SUCCESS;
        }

        GameFile::forgetFailedRaHashes();

        $ids = Game::query()
            ->where('retroachievements_status', RetroAchievementsStatus::HashFailed->value)
            ->pluck('id');

        Game::query()->whereIn('id', $ids)->update(['retroachievements_status' => RetroAchievementsStatus::Pending->value]);

        IdentifyGame::queueEach($ids);

        AppSetting::put(AppSetting::RA_HASHER_BUILD, $build);

        $this->info("Queued {$ids->count()} games RAHasher could not hash for another try.");

        return self::SUCCESS;
    }
}
