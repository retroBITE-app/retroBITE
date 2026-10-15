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

    public bool $auto = false;

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

    /** Queue a prune of every missing ROM whatever the days, for straight after tidying the library by hand. */
    public function pruneAllMissing(): void
    {
        Artisan::call('retrobite:library:prune', ['--days' => 0]);

        Flux::toast(variant: 'success', text: __('Queued. Every missing ROM, and the games left without any, will be removed.'));
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
            <x-settings.save form="library-settings" />
        </x-slot>

        <form id="library-settings" wire:submit="save" class="grid grid-cols-1 gap-6 lg:grid-cols-12">
            <x-settings.card
                class="lg:col-span-7"
                icon="trash"
                :title="__('Remove games whose ROMs are gone')"
                :description="__('A ROM missing from the library this long is taken off its game. A game left with no ROMs — or that never had any — is removed with its details and artwork.')"
            >
                <div class="flex flex-col gap-5">
                    <x-field
                        :label="__('Missing for')"
                        :info="__('A scan marks a ROM missing the first time it cannot find it. Put the ROM back before then and the game keeps everything.')"
                        name="days"
                    >
                        <flux:input.group>
                            <flux:input type="number" min="1" max="3650" wire:model="days" class="max-w-32" />
                            <flux:input.group.suffix>{{ __('days') }}</flux:input.group.suffix>
                        </flux:input.group>
                    </x-field>

                    <flux:switch wire:model="auto"
                                 :label="__('Prune automatically every night')"
                                 :description="__('At 04:30. Off, games are only removed when you press Prune now.')" />

                    <div class="flex flex-wrap items-center gap-3 border-t border-raised pt-4">
                        <flux:button size="sm" variant="filled" icon="trash" type="button" wire:click="pruneNow"
                                     wire:confirm="{{ __('Are you sure? ROMs missing for longer than the days above, and every game left without ROMs, will be removed with their details and artwork. This cannot be undone.') }}">
                            {{ __('Prune now') }}
                        </flux:button>

                        <flux:button size="sm" variant="danger" icon="trash" type="button" wire:click="pruneAllMissing"
                                     wire:confirm="{{ __('Are you sure? Every ROM marked missing, however recently, and every game left without ROMs, will be removed with their details and artwork. This cannot be undone.') }}">
                            {{ __('Prune all missing') }}
                        </flux:button>

                        @php(['games' => $games, 'files' => $files] = $this->wouldGo)
                        <p class="text-sm text-fg-faint">
                            {{ trans_choice('{0} No game|{1} :count game|[2,*] :count games', $games, ['count' => $games]) }}
                            {{ trans_choice('{0} and no missing ROM would be removed now.|{1} and :count missing ROM would be removed now.|[2,*] and :count missing ROMs would be removed now.', $files, ['count' => $files]) }}
                        </p>
                    </div>
                </div>
            </x-settings.card>

            <x-settings.card class="lg:col-span-5" :kicker="__('Kept, whatever the setting')">
                <ul class="flex flex-col gap-3 text-sm text-fg-soft">
                    <x-icon-note icon="document">{{ __('A game with any ROM still on the disk — only its missing ones go.') }}</x-icon-note>
                    <x-icon-note icon="arrow-path">{{ __('A game or ROM with a conversion still queued or running.') }}</x-icon-note>
                </ul>
            </x-settings.card>
        </form>
    </x-settings.layout>
</section>
