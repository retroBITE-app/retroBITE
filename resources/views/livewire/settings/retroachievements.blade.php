<?php

use App\Jobs\RetroAchievements\ReconcileProgress;
use App\Jobs\RetroAchievements\SyncHashIndex;
use App\Jobs\RetroAchievements\SyncRecentUnlocks;
use App\Models\AppSetting;
use App\Models\RaConsoleSync;
use App\Services\RetroAchievementsService;
use App\Support\RetroAchievements\LibraryConsoles;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('RetroAchievements')] class extends Component
{
    /**
     * Embedded in onboarding rather than on its own page: no tabs, and Save
     * moves setup on instead of toasting.
     */
    #[Locked]
    public bool $onboarding = false;

    public string $username = '';

    /**
     * Left blank when a key is already stored.
     *
     * The stored key is never sent to the browser — the field shows a
     * placeholder instead, and an empty submission means "leave it alone"
     * rather than "clear it".
     */
    public string $apiKey = '';

    public bool $hardcorePrimary = true;

    public function mount(): void
    {
        $this->username = (string) (auth()->user()?->retroachievements_username ?? '');
        $this->hardcorePrimary = AppSetting::enabled(AppSetting::RA_HARDCORE_PRIMARY);
    }

    /**
     * Whether a key is stored at all.
     *
     * The settings table is the only place one lives now — the environment
     * used to seed it, and does not any more. A key you have to redeploy to
     * change is a key nobody changes, and a newcomer should not have to edit a
     * file before achievements work.
     */
    #[Computed]
    public function hasKey(): bool
    {
        return AppSetting::getSecret(AppSetting::RA_API_KEY) !== null;
    }

    #[Computed]
    public function linked(): bool
    {
        return $this->username !== '' && $this->hasKey;
    }

    /**
     * Which consoles have an index, and how fresh it is.
     *
     * @return Collection<int, array{id: int, consoles: string, synced: ?string, games: int}>
     */
    #[Computed]
    public function indexes(): Collection
    {
        $synced = RaConsoleSync::query()->get()->keyBy('ra_console_id');

        return LibraryConsoles::mapped()
            ->map(fn (array $keys, $raConsoleId) => [
                'id' => (int) $raConsoleId,
                'consoles' => implode(', ', $keys),
                'synced' => $synced->get((int) $raConsoleId)?->synced_at?->diffForHumans(),
                'games' => (int) ($synced->get((int) $raConsoleId)?->games ?? 0),
            ])
            ->values();
    }

    public function save(RetroAchievementsService $provider): void
    {
        // RetroAchievements' own rules, quoted back by its API: "The u must be
        // between 2 and 20 characters" and "may only contain letters and
        // numbers". Worth enforcing here because the mistake this catches — a
        // 32-character API key typed into the username field — is one somebody
        // can make twice without the interface ever objecting.
        $this->validate([
            'username' => ['nullable', 'string', 'between:2,20', 'alpha_num'],
            'apiKey' => ['nullable', 'string', 'max:255'],
        ], [
            'username.between' => __('A RetroAchievements username is 2 to 20 characters. An API key is longer — that goes in the field below.'),
            'username.alpha_num' => __('A RetroAchievements username is letters and numbers only.'),
        ]);

        // Save the key first, so a key typed in the same submission is the one
        // the check below authenticates with.
        if ($this->apiKey !== '') {
            AppSetting::putSecret(AppSetting::RA_API_KEY, $this->apiKey);
            $this->apiKey = '';

            unset($this->hasKey);
        }

        // Null means the question could not be put — no key yet, or the
        // network is down. The form saves anyway then: refusing on the
        // strength of an outage would be worse than saving a typo.
        if ($this->username !== '' && $provider->userExists($this->username) === false) {
            $this->addError('username', __('RetroAchievements has no account by that name.'));

            return;
        }

        auth()->user()?->forceFill([
            'retroachievements_username' => $this->username !== '' ? $this->username : null,
        ])->save();

        AppSetting::put(AppSetting::RA_HARDCORE_PRIMARY, $this->hardcorePrimary);

        unset($this->hasKey, $this->linked);

        if ($this->onboarding) {
            $this->finishOnboarding();

            return;
        }

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    /**
     * The last onboarding step, and the one that may be skipped: achievements
     * are an extra, and this screen takes an account any time.
     */
    public function finishOnboarding(): void
    {
        if (! $this->onboarding) {
            return;
        }

        AppSetting::put(AppSetting::IS_ONBOARDED, true);

        Flux::toast(variant: 'success', text: __('Welcome to retroBITE.'));

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function forgetKey(): void
    {
        AppSetting::putSecret(AppSetting::RA_API_KEY, null);

        unset($this->hasKey, $this->linked);

        Flux::toast(variant: 'success', text: __('API key removed.'));
    }

    public function syncProgress(bool $full = false): void
    {
        $user = auth()->user();

        if ($user === null || (string) $user->retroachievements_username === '') {
            Flux::toast(variant: 'warning', text: __('Set a RetroAchievements username first.'));

            return;
        }

        // Queued, never run here. Nothing in a request may wait on the
        // network: the page has to render the same with the cable pulled.
        dispatch($full ? new ReconcileProgress($user->id) : new SyncRecentUnlocks($user->id));

        Flux::toast(variant: 'success', text: __('Queued. Progress will update as the worker gets to it.'));
    }

    /**
     * Queue the nightly hash-index download now, for every console in the
     * library — for somebody who has just added games or a key and does not
     * want to wait for three in the morning.
     */
    public function syncIndex(): void
    {
        if (! $this->hasKey) {
            Flux::toast(variant: 'warning', text: __('Add an API key first.'));

            return;
        }

        // Queued, never run here: an index is megabytes per console.
        $queued = SyncHashIndex::queueForLibrary();

        if ($queued === 0) {
            Flux::toast(variant: 'warning', text: __('No console in the library is on RetroAchievements.'));

            return;
        }

        Flux::toast(variant: 'success', text: trans_choice('Queued the index for :count console.|Queued the indexes for :count consoles.', $queued, ['count' => $queued]));
    }

    /**
     * Queue the 03:30 job now: download the set of every game identified but
     * never fetched. Through the command itself, so the button and the
     * schedule cannot disagree about which sets those are.
     */
    public function syncMissingSets(): void
    {
        if (! $this->hasKey) {
            Flux::toast(variant: 'warning', text: __('Add an API key first.'));

            return;
        }

        Artisan::call('retrobite:ra:sync-sets', ['--missing' => true, '--queue' => true]);

        Flux::toast(variant: 'success', text: __('Queued. Achievements will appear as the worker gets to them.'));
    }
}; ?>

<section class="w-full">
    @unless ($onboarding)
        @include('partials.settings-heading')
    @endunless

    <x-settings.layout :show-tabs="! $onboarding" :heading="__('RetroAchievements')" :subheading="__('Achievements, the hash index and your progress')">
        <x-slot name="actions">
            @if ($onboarding)
                <flux:button variant="ghost" type="button" wire:click="finishOnboarding">{{ __('Skip for now') }}</flux:button>
            @endif
            <flux:button variant="primary" type="submit" form="retroachievements-settings">{{ $onboarding ? __('Finish setup') : __('Save') }}</flux:button>
        </x-slot>

        <form id="retroachievements-settings" wire:submit="save" class="grid grid-cols-1 gap-6 lg:grid-cols-12">
            <div @class(['flex flex-col gap-6', 'lg:col-span-6' => ! $onboarding, 'lg:col-span-12' => $onboarding])>
                <div class="rounded-xl border border-line bg-surface p-5">
                    <div class="mb-4 flex items-center gap-2.5">
                        <flux:icon.trophy class="size-[17px] text-accent" />
                        {{-- Not 'RetroAchievements': the screen is called that
                             now, and this card is the account within it. --}}
                        <p class="flex-1 text-sm text-fg-bright">{{ __('Account') }}</p>
                        <span @class([
                            'kicker rounded-md border px-1.75 py-0.75',
                            'border-accent/40 text-accent' => $this->linked,
                            'border-line-input text-fg-faint' => ! $this->linked,
                        ])>{{ $this->linked ? __('Linked') : __('Not linked') }}</span>
                    </div>

                    <p class="mb-4 text-sm text-fg-soft">
                        {{ __('RetroAchievements adds achievements to retro games. retroBITE matches your library against its sets and follows what you have unlocked. It needs your account name and a web API key, both free.') }}
                    </p>

                    {{-- Straight to the tab the key is on. It is not on the
                         profile and not on the front of Settings, and hunting
                         for it is the step people write in to ask about. --}}
                    <div class="mb-4">
                        <flux:button size="xs" variant="ghost" icon-trailing="arrow-top-right-on-square"
                                     href="https://retroachievements.org/settings?tab=applications" target="_blank" rel="noopener">
                            {{ __('Find your API key') }}
                        </flux:button>
                        <p class="mt-1.5 text-xs text-fg-faint">
                            {{ __('Opens Settings → Applications on retroachievements.org, where the web API key is shown. Signing up is free.') }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-4">
                        <flux:input wire:model="username" :label="__('Username')"
                                    :placeholder="__('Your account name, not your key')"
                                    :description="__('The name on your RetroAchievements profile — the last part of retroachievements.org/user/…. Achievement sets are fetched whatever this says; progress needs it to be right.')" />

                        <flux:input wire:model="apiKey" type="password" :label="__('API key')"
                                    :placeholder="$this->hasKey ? __('Stored — type to replace it') : __('From Settings → Applications on retroachievements.org')"
                                    :description="__('Kept encrypted in the database and never shown again once saved.')" />

                        @if ($this->hasKey)
                            <div>
                                <flux:button size="xs" variant="danger" wire:click="forgetKey" type="button">
                                    {{ __('Remove stored key') }}
                                </flux:button>
                            </div>
                        @endif

                        <flux:switch wire:model="hardcorePrimary"
                                     :label="__('Lead with hardcore')"
                                     :description="__('Show your hardcore score rather than your softcore one.')" />
                    </div>
                </div>
            </div>

            {{-- Nothing to read back on an install being set up. --}}
            @unless ($onboarding)
            <div class="flex flex-col gap-6 lg:col-span-6">
                <div class="rounded-xl border border-line bg-surface p-5">
                    <p class="kicker mb-1 text-fg-faint">{{ __('Hash index') }}</p>
                    <p class="mb-4 text-sm text-fg-soft">
                        {{ __('Downloaded once per console so that identifying a game costs no request. Refreshed nightly.') }}
                    </p>

                    @if ($this->indexes->isEmpty())
                        <p class="text-sm text-fg-faint">{{ __('No console in the library is on RetroAchievements.') }}</p>
                    @else
                        <dl class="flex flex-col gap-2.5">
                            @foreach ($this->indexes as $index)
                                <div wire:key="idx-{{ $index['id'] }}" class="flex items-baseline gap-3 border-b border-raised pb-2.5 last:border-0 last:pb-0">
                                    <dt class="font-mono text-xs text-fg-soft">{{ $index['consoles'] }}</dt>
                                    <dd class="ml-auto font-mono text-xs text-fg-dim">
                                        {{ $index['synced'] ?? __('never') }}
                                        @if ($index['games'] > 0)
                                            <span class="text-fg-faint">· {{ $index['games'] }}</span>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>

                        <div class="mt-4">
                            <flux:button size="sm" variant="filled" type="button" wire:click="syncIndex">
                                {{ __('Refresh indexes now') }}
                            </flux:button>
                            <p class="mt-1.5 text-xs text-fg-faint">
                                {{ __('Queues the nightly download for every console listed. Games that had no match are checked again once it lands.') }}
                            </p>
                        </div>

                        <div class="mt-3">
                            <flux:button size="sm" variant="ghost" type="button" wire:click="syncMissingSets">
                                {{ __('Fetch missing sets') }}
                            </flux:button>
                            <p class="mt-1.5 text-xs text-fg-faint">
                                {{ __('Downloads the achievements for every game identified but not fetched yet — run it after refreshing the indexes. The same job runs nightly, half an hour after them.') }}
                            </p>
                        </div>
                    @endif
                </div>

                <div class="rounded-xl border border-line bg-surface p-5">
                    <p class="kicker mb-1 text-fg-faint">{{ __('Progress') }}</p>
                    <p class="mb-4 text-sm text-fg-soft">
                        {{ __('Both run on a schedule — recent unlocks every quarter of an hour, the reconcile overnight. These queue the same work now.') }}
                    </p>

                    {{-- Two buttons because they answer different questions,
                         and one of them is dear. Said on the page rather than
                         left to be guessed from the labels. --}}
                    <div class="flex flex-col gap-3">
                        <div>
                            <flux:button size="sm" variant="filled" type="button" wire:click="syncProgress(false)">
                                {{ __('Sync recent unlocks') }}
                            </flux:button>
                            <p class="mt-1.5 text-xs text-fg-faint">
                                {{ __('One request for everything you have unlocked since the last run. Cheap, and what the schedule uses.') }}
                            </p>
                        </div>

                        <div>
                            <flux:button size="sm" variant="ghost" type="button" wire:click="syncProgress(true)">
                                {{ __('Reconcile everything') }}
                            </flux:button>
                            <p class="mt-1.5 text-xs text-fg-faint">
                                {{ __('Compares every game against RetroAchievements and re-fetches the ones that disagree. Catches revoked unlocks, re-scored sets and anything a long outage missed.') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            @endunless
        </form>
    </x-settings.layout>
</section>
