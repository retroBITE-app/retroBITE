<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class ResetUserPassword extends Command
{
    protected $signature = 'user:password
                            {user? : Username or email address}
                            {--password= : The new password (prompted for when omitted)}';

    protected $description = 'Reset a user password by username or email';

    public function handle(): int
    {
        $identifier = $this->argument('user') ?: text(
            label: 'Which user?',
            placeholder: 'username or email address',
            required: true,
        );

        $user = $this->resolveUser($identifier);

        if (! $user instanceof User) {
            return self::FAILURE;
        }

        $password = $this->option('password') ?: promptPassword(
            label: "New password for {$user->username}",
            required: true,
        );

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', Password::default()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user->password = $password;
        $user->save();

        $this->components->info("Password updated for {$user->username} <{$user->email}>.");

        return self::SUCCESS;
    }

    /**
     * Find the user, falling back to a partial match so a half-remembered name
     * still gets you there.
     */
    private function resolveUser(string $identifier): ?User
    {
        $user = User::query()
            ->where('username', $identifier)
            ->orWhere('email', $identifier)
            ->first();

        if ($user) {
            return $user;
        }

        $matches = User::query()
            ->where('username', 'like', "%{$identifier}%")
            ->orWhere('email', 'like', "%{$identifier}%")
            ->orWhere('name', 'like', "%{$identifier}%")
            ->orderBy('username')
            ->limit(25)
            ->get();

        if ($matches->isEmpty()) {
            $this->components->error("No user matches [{$identifier}].");

            return null;
        }

        if ($matches->count() === 1) {
            $only = $matches->first();
            $this->components->info("Matched {$only->username} <{$only->email}>.");

            return $only;
        }

        $choice = select(
            label: "Several users match [{$identifier}] — which one?",
            options: $matches->mapWithKeys(
                fn (User $user): array => [$user->id => "{$user->username} <{$user->email}>"]
            )->all(),
        );

        return $matches->firstWhere('id', $choice);
    }
}
