<?php

use App\Models\AppSetting;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('UI settings')] class extends Component
{
    public bool $scanlines = true;

    /** 'logo' or 'text': what heads the game page's hero. */
    public string $heroTitle = 'text';

    /** Whether each console's shelf offers ROM uploads. */
    public bool $uploads = false;

    public function mount(): void
    {
        $this->scanlines = AppSetting::enabled(AppSetting::UI_SCANLINES);
        $this->heroTitle = (string) AppSetting::get(AppSetting::UI_HERO_TITLE);
        $this->uploads = AppSetting::enabled(AppSetting::UI_UPLOADS);
    }

    public function save(): void
    {
        $this->validate([
            'heroTitle' => ['required', 'in:logo,text'],
        ]);

        AppSetting::put(AppSetting::UI_SCANLINES, $this->scanlines);
        AppSetting::put(AppSetting::UI_HERO_TITLE, $this->heroTitle);
        AppSetting::put(AppSetting::UI_UPLOADS, $this->uploads);

        Flux::toast(variant: 'success', text: __('UI settings saved.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('UI')" :subheading="__('How retroBite looks, independent of what it fetches')">
        {{-- Outside the form, bound back to it by id, as on the Media screen. --}}
        <x-slot name="actions">
            <flux:button variant="primary" type="submit" form="interface-settings">{{ __('Save') }}</flux:button>
        </x-slot>

        <form id="interface-settings" wire:submit="save" class="flex flex-col gap-6">
            {{-- The docs viewer's Preview / Markdown pill rather than Flux's
                 segmented radio, so the two two-way switches look alike. --}}
            <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-line bg-surface p-5">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-fg-bright">{{ __('Game page title') }}</p>
                    <p class="mt-1 text-sm text-fg-muted">
                        {{ __('What heads a game\'s page: the provider\'s logo, or the title as text. A game with no logo shows its title either way.') }}
                    </p>
                </div>

                <div role="radiogroup" aria-label="{{ __('Game page title') }}" class="flex shrink-0 items-center gap-0.5 rounded-lg border border-line-input p-0.5">
                    @foreach (['logo' => __('Logo'), 'text' => __('Text')] as $value => $label)
                        <button
                            type="button"
                            role="radio"
                            aria-checked="{{ $heroTitle === $value ? 'true' : 'false' }}"
                            wire:click="$set('heroTitle', '{{ $value }}')"
                            @class([
                                'cursor-pointer rounded-md px-2.75 py-1 text-xs transition-colors',
                                'bg-accent-tint/15 text-accent' => $heroTitle === $value,
                                'text-fg-dim hover:bg-hover hover:text-fg' => $heroTitle !== $value,
                            ])
                        >{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            <div class="rounded-xl border border-line bg-surface p-5">
                <flux:switch wire:model="scanlines"
                             :label="__('CRT scanlines')"
                             :description="__('Lays the faint horizontal banding of a tube over key art and the sign-in backdrop. Part of the look rather than decoration you can ignore — turn it off for a flat presentation.')" />
            </div>

            <div class="rounded-xl border border-line bg-surface p-5">
                <flux:switch wire:model="uploads"
                             :label="__('ROM uploads')"
                             :description="__('Adds Upload files to each console\'s Actions menu. Files land in the folders that console\'s layout reads games from.')" />
            </div>
        </form>
    </x-settings.layout>
</section>
