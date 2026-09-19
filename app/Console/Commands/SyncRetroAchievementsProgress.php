<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RetroAchievements\ReconcileProgress;
use App\Jobs\RetroAchievements\SyncRecentUnlocks;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SyncRetroAchievementsProgress extends Command
{
    protected $signature = 'retrobite:ra:sync-progress
                            {--full : Reconcile every played game against the site instead of pulling recent unlocks.}
                            {--user= : Only this user id, username or email.}';

    /*
     * Always queued. Both paths make network calls and the reconciliation can
     * fan out to a job per game, so there is nothing useful to run inline.
     */
    protected $description = 'Queue a RetroAchievements progress sync';

    public function handle(): int
    {
        $users = $this->users();

        if ($users->isEmpty()) {
            $this->warn('No user has a RetroAchievements username set.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            // --full is the reconciliation: one paginated endpoint describes
            // every game they have played, and only the ones that disagree
            // with what we hold get a request of their own. The default is the
            // cheap pulse, one request for everything unlocked lately.
            $job = $this->option('full')
                ? new ReconcileProgress($user->id)
                : new SyncRecentUnlocks($user->id);

            dispatch($job);

            $this->line(sprintf('%-20s queued', $user->username ?? $user->email));
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, User>
     */
    private function users()
    {
        $query = User::query()->whereNotNull('retroachievements_username')
            ->where('retroachievements_username', '!=', '');

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
