<?php

use App\Exceptions\ScreenScraper\ScreenScraperException;
use App\Models\AppSetting;
use App\Services\ScreenScraperService;
use App\Support\ScreenScraperCredentials;
use App\Support\ScreenScraperQuota;
use Flux\Flux;
use Illuminate\Support\Arr;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('ScreenScraper')] class extends Component
{
    /**
     * Embedded in onboarding rather than on its own page: no tabs, and Save
     * moves setup on instead of toasting.
     */
    #[Locked]
    public bool $onboarding = false;

    public string $username = '';

    /**
     * Left blank when a password is already stored.
     *
     * The stored one is never sent to the browser — the field shows a
     * placeholder instead, and an empty submission means "leave it alone"
     * rather than "clear it".
     */
    public string $password = '';

    public function mount(): void
    {
        // The name is not a secret and is shown back: an account name you
        // cannot read is one you cannot check against the site when lookups
        // start answering as somebody else.
        $this->username = ScreenScraperCredentials::user();
    }

    #[Computed]
    public function hasPassword(): bool
    {
        return ScreenScraperCredentials::password() !== '';
    }

    #[Computed]
    public function linked(): bool
    {
        return $this->username !== '' && $this->hasPassword;
    }

    /**
     * What the provider last said this account may do.
     *
     * Written by whatever response came back most recently rather than asked
     * for here, so an idle page costs nothing. Null until something has called
     * out at all.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function quota(): ?array
    {
        return ScreenScraperQuota::current();
    }

    public function save(): void
    {
        // Required in onboarding although this screen allows it blank: without
        // an account lookups ride the shared developer allowance, which a first
        // scan of a library runs through almost at once. A stored password
        // counts, so coming back to the step does not ask for it again.
        $required = $this->onboarding ? 'required' : 'nullable';

        $this->validate([
            'username' => [$required, 'string', 'max:255'],
            'password' => [$this->hasPassword ? 'nullable' : $required, 'string', 'max:255'],
        ]);

        // An email address is the mistake worth catching: ScreenScraper's
        // login is the account name shown on the profile, and an email is
        // accepted by the form and then silently answered on the developer
        // account instead.
        if ($this->username !== '' && str_contains($this->username, '@')) {
            $this->addError('username', __('ScreenScraper wants the account name from your profile, not the email address you sign in with.'));

            return;
        }

        AppSetting::put(AppSetting::SS_USER, $this->username !== '' ? $this->username : null);

        if ($this->password !== '') {
            AppSetting::putSecret(AppSetting::SS_PASSWORD, $this->password);
            $this->password = '';
        }

        unset($this->hasPassword, $this->linked);

        if ($this->onboarding) {
            $this->redirectRoute('onboarding.step', ['step' => 'achievements'], navigate: true);

            return;
        }

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    /** Drop the stored password, leaving the name. */
    public function forgetPassword(): void
    {
        AppSetting::putSecret(AppSetting::SS_PASSWORD, null);

        unset($this->hasPassword, $this->linked);

        Flux::toast(variant: 'success', text: __('Password removed.'));
    }

    /**
     * Ask the provider who it thinks is asking.
     *
     * The only check worth having, and the reason this button exists rather
     * than a green tick beside the password field. ScreenScraper never rejects
     * a login it does not recognise: it answers on the developer account
     * instead, with the developer account's much smaller allowance, and every
     * game lookup looks exactly the same either way. The `ssuser` block is the
     * one place the difference shows.
     */
    public function check(ScreenScraperService $provider): void
    {
        try {
            $account = $provider->account();
        } catch (ScreenScraperException $e) {
            // Already redacted by the service: the credentials ride in the
            // query string and must not reach a toast either.
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        unset($this->quota);

        if ($account === []) {
            Flux::toast(variant: 'warning', text: __('The provider answered without saying which account it answered as.'));

            return;
        }

        $id = (string) Arr::get($account, 'id', '');

        if ($id === '' || ($this->username !== '' && strcasecmp($id, $this->username) !== 0)) {
            Flux::toast(variant: 'warning', text: __('The provider answered as :id, not as :expected. The login was not accepted, and the allowance is the developer account\'s.', [
                'id' => $id !== '' ? $id : __('nobody'),
                'expected' => $this->username,
            ]));

            return;
        }

        Flux::toast(variant: 'success', text: __('Signed in as :id. :used of :max lookups used today.', [
            'id' => $id,
            'used' => (string) Arr::get($account, 'requeststoday', '0'),
            'max' => (string) Arr::get($account, 'maxrequestsperday', '0'),
        ]));
    }
}; ?>

<section class="w-full">
    @unless ($onboarding)
        @include('partials.settings-heading')
    @endunless

    <x-settings.layout :show-tabs="! $onboarding" :heading="__('ScreenScraper')" :subheading="__('The account that identifies games and fetches artwork')">
        <x-slot name="actions">
            <x-settings.save form="screenscraper-settings" :onboarding="$onboarding" />
        </x-slot>

        <form id="screenscraper-settings" wire:submit="save" class="grid grid-cols-1 gap-6 lg:grid-cols-12">
            <div @class(['flex flex-col gap-6', 'lg:col-span-6' => ! $onboarding, 'lg:col-span-12' => $onboarding])>
                <x-settings.card
                    icon="magnifying-glass"
                    :title="__('Account')"
                    :description="__('retroBITE identifies games against ScreenScraper and downloads its artwork. It works without an account, on a shared allowance that runs out quickly; a free account of your own raises it to 20 000 lookups a day.')"
                >
                    <x-slot name="badge">
                        <x-settings.linked-badge :linked="$this->linked" />
                    </x-slot>

                    <div class="mb-4">
                        <flux:button size="xs" variant="ghost" icon-trailing="arrow-top-right-on-square"
                                     href="https://www.screenscraper.fr/membreinscription.php" target="_blank" rel="noopener">
                            {{ __('Create a ScreenScraper account') }}
                        </flux:button>
                    </div>

                    <div class="flex flex-col gap-4">
                        <flux:input wire:model="username" :label="__('Account name')"
                                    :placeholder="__('The name on your profile')"
                                    :description="__('The login shown on your ScreenScraper profile, not the email address you sign in with. An email is accepted and then quietly ignored.')" />

                        <x-settings.secret-input
                            model="password"
                            :label="__('Password')"
                            :stored="$this->hasPassword"
                            :placeholder="__('Your ScreenScraper password')"
                            forget="forgetPassword"
                            :forget-label="__('Remove stored password')"
                        />
                    </div>
                </x-settings.card>
            </div>

            {{-- Nothing to read back on an install being set up. --}}
            @unless ($onboarding)
            <div class="flex flex-col gap-6 lg:col-span-6">
                <x-settings.card
                    :kicker="__('Check the login')"
                    :description="__('ScreenScraper does not refuse a login it does not recognise — it answers on the developer account instead, with a far smaller allowance, and every lookup looks the same either way. This asks it who it thinks is asking.')"
                >
                    <flux:button size="sm" variant="filled" type="button" wire:click="check"
                                 wire:loading.attr="disabled" wire:target="check">
                        {{ __('Check now') }}
                    </flux:button>
                </x-settings.card>

                <x-settings.card
                    :kicker="__('Allowance')"
                    :description="__('Read back from the last answer the provider gave, so it is whatever it said rather than a number written down here.')"
                >
                    @if ($this->quota === null)
                        <x-empty-state variant="inline" :title="__('Nothing yet — it fills in the first time a lookup runs.')" />
                    @else
                        <dl class="flex flex-col gap-2.5">
                            <x-kv-row :label="__('Lookups today')">
                                <x-fraction :value="Arr::get($this->quota, 'requests_today')" :of="Arr::get($this->quota, 'max_requests_per_day')" />
                            </x-kv-row>

                            {{-- Its own line because it is its own allowance,
                                 and the smaller of the two by a factor of ten:
                                 a library full of unrecognised dumps runs out
                                 of failures long before it runs out of lookups. --}}
                            <x-kv-row :label="__('Failed lookups today')">
                                <x-fraction :value="Arr::get($this->quota, 'failed_today')" :of="Arr::get($this->quota, 'max_failed_per_day')" />
                            </x-kv-row>

                            <x-kv-row :label="__('Threads')">{{ Arr::get($this->quota, 'max_threads') }}</x-kv-row>
                        </dl>
                    @endif
                </x-settings.card>
            </div>
            @endunless
        </form>
    </x-settings.layout>
</section>
