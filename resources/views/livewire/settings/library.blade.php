<?php

use App\Jobs\PruneGame;
use App\Models\AppSetting;
use Flux\Flux;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Library settings')] class extends Component
{
    /** Days every file of a game may be missing before the game is pruned. */
    public string $days = '30';

    public bool $auto = true;

    public function mount(): void
    {
        $this->days = (string) AppSetting::get(AppSetting::PRUNE_MISSING_AFTER_DAYS);
        $this->auto = AppSetting::enabled(AppSetting::PRUNE_MISSING_AUTO);
    }

    /**
     * What a prune at the saved setting would remove right now.
     *
     * @return array{games: int, files: int}
     */
    #[Computed]
    public function wouldGo(): array
    {
        $cutoff = PruneGame::cutoff(PruneGame::savedDays());

        return [
            'games' => PruneGame::wouldRemove($cutoff)->count(),
            'files' => PruneGame::staleFiles($cutoff)->count(),
        ];
    }

    public function save(): void
    {
        $this->validateDays();

        AppSetting::put(AppSetting::PRUNE_MISSING_AFTER_DAYS, (int) $this->days);
        AppSetting::put(AppSetting::PRUNE_MISSING_AUTO, $this->auto);
        unset($this->wouldGo);

        Flux::toast(variant: 'success', text: __('Library settings saved.'));
    }

    /** Queue a prune at the days typed, saved or not: the button does what the field says. */
    public function pruneNow(): void
    {
        $this->validateDays();

        // The command the schedule runs, so the button and the night cannot disagree.
        Artisan::call('retrobite:library:prune', ['--days' => (int) $this->days]);

        Flux::toast(variant: 'success', text: __('Queued. ROMs missing for more than :days days, and the games left without any, will be removed.', ['days' => (int) $this->days]));
    }

    private function validateDays(): void
    {
        $this->validate(['days' => ['required', 'integer', 'min:1', 'max:3650']]);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Library')" :subheading="__('Housekeeping for the games retroBITE keeps track of')">
        {{-- Outside the form, bound back to it by id, as on the Media tab. --}}
        <x-slot name="actions">
            <flux:button variant="primary" type="submit" form="library-settings">{{ __('Save') }}</flux:button>
        </x-slot>

        <form id="library-settings" wire:submit="save" class="grid grid-cols-1 gap-6 lg:grid-cols-12">
            <div class="rounded-xl border border-line bg-surface p-5 lg:col-span-7">
                <div class="mb-2 flex items-center gap-2.5">
                    <flux:icon.trash class="size-[17px] text-accent" />
                    <p class="flex-1 text-sm text-fg-bright">{{ __('Remove games whose ROMs are gone') }}</p>
                </div>

                <p class="mb-5 text-sm text-fg-soft">
                    {{ __('A ROM missing from the library this long is taken off its game. A game left with no ROMs — or that never had any — is removed with its details and artwork.') }}
                </p>

                <div class="flex flex-col gap-5">
                    <flux:field>
                        <div class="flex items-center gap-2">
                            <flux:label>{{ __('Missing for') }}</flux:label>
                            <x-info :text="__('A scan marks a ROM missing the first time it cannot find it. Put the ROM back before then and the game keeps everything.')" />
                        </div>
                        <flux:input.group>
                            <flux:input type="number" min="1" max="3650" wire:model="days" class="max-w-32" />
                            <flux:input.group.suffix>{{ __('days') }}</flux:input.group.suffix>
                        </flux:input.group>
                        <flux:error name="days" />
                    </flux:field>

                    <flux:switch wire:model="auto"
                                 :label="__('Prune automatically every night')"
                                 :description="__('At 04:30. Off, games are only removed when you press Prune now.')" />

                    <div class="flex flex-wrap items-center gap-3 border-t border-raised pt-4">
                        <flux:button size="sm" variant="filled" icon="trash" type="button" wire:click="pruneNow"
                                     wire:confirm="{{ __('Are you sure? ROMs missing for longer than the days above, and every game left without ROMs, will be removed with their details and artwork. This cannot be undone.') }}">
                            {{ __('Prune now') }}
                        </flux:button>

                        @php(['games' => $games, 'files' => $files] = $this->wouldGo)
                        <p class="text-sm text-fg-faint">
                            {{ trans_choice('{0} No game|{1} :count game|[2,*] :count games', $games, ['count' => $games]) }}
                            {{ trans_choice('{0} and no missing ROM would be removed now.|{1} and :count missing ROM would be removed now.|[2,*] and :count missing ROMs would be removed now.', $files, ['count' => $files]) }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-line bg-surface p-5 lg:col-span-5">
                <p class="kicker mb-3 text-fg-faint">{{ __('Kept, whatever the setting') }}</p>

                <ul class="flex flex-col gap-3 text-sm text-fg-soft">
                    <li class="flex gap-2.5">
                        <flux:icon.document variant="micro" class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                        <span>{{ __('A game with any ROM still on the disk — only its missing ones go.') }}</span>
                    </li>
                    <li class="flex gap-2.5">
                        <flux:icon.arrow-path variant="micro" class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                        <span>{{ __('A game or ROM with a conversion still queued or running.') }}</span>
                    </li>
                </ul>
            </div>
        </form>
    </x-settings.layout>
</section>
