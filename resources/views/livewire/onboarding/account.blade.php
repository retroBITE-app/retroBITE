<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    public string $username = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * Make the first account, sign it in and move on to the interface step.
     *
     * Email and name take the user:create defaults: there is no mailer to
     * verify an address through, and both can be changed under Settings → User.
     */
    public function create(): void
    {
        $validated = $this->validate([
            'username' => $this->usernameRules(),
            'password' => $this->passwordRules(),
        ]);

        $username = (string) Arr::get($validated, 'username');

        // Checked again inside the write: the page is public until an account
        // exists, and two browsers racing the form must not both get one.
        $user = DB::transaction(function () use ($validated, $username): ?User {
            if (User::query()->lockForUpdate()->exists()) {
                return null;
            }

            $user = User::create([
                'name' => $username,
                'username' => $username,
                'email' => $username.'@retrobite.local',
                'password' => Arr::get($validated, 'password'),
            ]);

            // Fortify blocks unverified accounts from most routes.
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user;
        });

        if ($user === null) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        Auth::login($user);
        session()->regenerate();

        $this->redirectRoute('onboarding.step', ['step' => 'interface'], navigate: true);
    }
}; ?>

<div>
    <p class="text-center text-sm leading-relaxed text-fg-dim">
        {{ __('Create the account you will sign in to retroBITE with. It is for this web interface only — the SMB and FTP shares keep the login set in .env.') }}
    </p>

    <form wire:submit="create" class="mt-8 flex flex-col gap-4">
        <flux:input
            wire:model="username"
            :label="__('Username')"
            type="text"
            icon="user"
            required
            autofocus
            autocomplete="username"
            :placeholder="__('Choose a username')"
        />

        <flux:input
            wire:model="password"
            :label="__('Password')"
            type="password"
            icon="lock-closed"
            required
            autocomplete="new-password"
            :placeholder="__('Choose a password')"
            viewable
        />

        <flux:input
            wire:model="password_confirmation"
            :label="__('Confirm password')"
            type="password"
            icon="lock-closed"
            required
            autocomplete="new-password"
            :placeholder="__('Type it again')"
            viewable
        />

        <button
            type="submit"
            data-test="create-account-button"
            class="mt-1 flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-accent-tint/55 bg-accent-tint/10 px-4 py-3 text-base text-accent transition-colors hover:bg-accent-tint/18 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
        >
            {{ __('Create account') }}
            <flux:icon.arrow-right variant="micro" />
        </button>
    </form>
</div>
