<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\text;

class CreateUser extends Command
{
    protected $signature = 'user:create
                            {username? : The username to log in with}
                            {--email= : Email address (defaults to <username>@retrobite.local)}
                            {--name= : Display name (defaults to the username)}
                            {--password= : The password (prompted for when omitted)}';

    protected $description = 'Create a new user';

    public function handle(): int
    {
        $username = $this->argument('username') ?: text(
            label: 'Username',
            placeholder: 'retrogamer',
            required: true,
        );

        // Derived rather than prompted for: it has a sensible default, and
        // prompting would make the command unusable non-interactively.
        $email = $this->option('email') ?: $username.'@retrobite.local';

        $name = $this->option('name') ?: $username;

        $password = $this->option('password') ?: promptPassword(
            label: "Password for {$username}",
            required: true,
        );

        $validator = Validator::make([
            'username' => $username,
            'email' => $email,
            'name' => $name,
            'password' => $password,
        ], [
            'username' => ['required', 'string', 'max:255', Rule::unique(User::class, 'username')],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', Password::default()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => $password,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->components->info("Created {$user->username} <{$user->email}>.");

        return self::SUCCESS;
    }
}
