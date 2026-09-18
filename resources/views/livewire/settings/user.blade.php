<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('User settings')] class extends Component
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    public string $name = '';

    public string $email = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * Update the password for the currently authenticated user.
     *
     * The current password is what authorises this, which is why the screen
     * itself needs no password.confirm gate in front of it.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: __('Password updated.'));
    }
};
?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('User settings') }}</flux:heading>

    <x-settings.layout :heading="__('User')" :subheading="__('Who you are here, and the password you sign in with')">
        {{-- Two forms rather than one: the two halves save independently, and a
             typo in a password must not cost somebody the name they just typed. --}}
        <form wire:submit="updateProfileInformation" class="grid w-full grid-cols-1 gap-6 lg:grid-cols-12">
            <div class="rounded-xl border border-line bg-surface p-5 lg:col-span-12">
                <p class="kicker mb-1 text-fg-faint">{{ __('Profile') }}</p>
                <p class="mb-4 text-sm text-fg-soft">{{ __('Your name and email address.') }}</p>

                {{-- Two fields that belong to one another, so they sit on one row
                     rather than each running the width of the pane. --}}
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
                    <div class="lg:col-span-6">
                        <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />
                    </div>

                    <div class="lg:col-span-6">
                        <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />
                    </div>

                    <div class="flex items-center gap-4 lg:col-span-12">
                        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                    </div>
                </div>
            </div>
        </form>

        <form method="POST" wire:submit="updatePassword" class="mt-6 grid w-full grid-cols-1 gap-6 lg:grid-cols-12">
            <div class="rounded-xl border border-line bg-surface p-5 lg:col-span-12">
                <p class="kicker mb-1 text-fg-faint">{{ __('Password') }}</p>
                <p class="mb-4 text-sm text-fg-soft">
                    {{ __('Use a long, random password. Changing it needs the one you sign in with today.') }}
                </p>

                {{-- The current password proves who is asking; the new one and its
                     confirmation are the change. A row each, so they read that way. --}}
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
                    <div class="lg:col-span-6">
                        <flux:input
                            wire:model="current_password"
                            :label="__('Current password')"
                            type="password"
                            required
                            autocomplete="current-password"
                            viewable
                        />
                    </div>

                    <div class="lg:col-span-6 lg:col-start-1">
                        <flux:input
                            wire:model="password"
                            :label="__('New password')"
                            type="password"
                            required
                            autocomplete="new-password"
                            passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                            viewable
                        />
                    </div>

                    <div class="lg:col-span-6">
                        <flux:input
                            wire:model="password_confirmation"
                            :label="__('Confirm password')"
                            type="password"
                            required
                            autocomplete="new-password"
                            passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                            viewable
                        />
                    </div>

                    <div class="flex items-center gap-4 lg:col-span-12">
                        <flux:button variant="primary" type="submit" data-test="update-password-button">{{ __('Save') }}</flux:button>
                    </div>
                </div>
            </div>
        </form>
    </x-settings.layout>
</section>
