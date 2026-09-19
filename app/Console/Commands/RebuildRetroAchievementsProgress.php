<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\RetroAchievementsProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class RebuildRetroAchievementsProgress extends Command
{
    protected $signature = 'retrobite:ra:rebuild-progress
                            {--user= : Only this user id, username or email.}';

    protected $description = 'Recount every RetroAchievements progress row from the unlock rows';

    /**
     * Rebuild the counters from scratch.
     *
     * The counters are denormalised, and denormalised numbers eventually
     * disagree with what they were derived from — a failed job halfway, a set
     * that changed while a sync was running, a migration. This is the way
     * back, and it goes through exactly the same code as the incremental sync,
     * so the two cannot drift in what they mean.
     */
    public function handle(RetroAchievementsProgress $progress): int
    {
        $users = $this->users();

        if ($users->isEmpty()) {
            $this->warn('No matching user.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            $count = $progress->recomputeAll($user->id);

            $this->line(sprintf('%-20s %d sets recounted', $user->username ?? $user->email, $count));
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, User>
     */
    private function users()
    {
        $query = User::query();

        if ($needle = $this->option('user')) {
            $query->where(function ($q) use ($needle) {
                $q->where('id', (int) $needle)
                    ->orWhere('username', $needle)
                    ->orWhere('email', $needle);
            });
        }

        return $query->get();
    }
}
