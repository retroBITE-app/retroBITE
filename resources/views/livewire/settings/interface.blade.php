<?php

use App\Enums\ColorScheme;
use App\Enums\ConsoleGrouping;
use App\Enums\ConsoleSort;
use App\Models\AppSetting;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('UI settings')] class extends Component
{
    /**
     * Embedded in onboarding rather than on its own page: no tabs, and Save
     * moves setup on instead of toasting.
     */
    #[Locked]
    public bool $onboarding = false;

    public bool $scanlines = true;

    /** 'logo' or 'text': what heads the game page's hero. */
    public string $heroTitle = 'text';

    /** Whether each console's shelf offers ROM uploads. */
    public bool $uploads = false;

    /** A ColorScheme value. Previewed on <html> as soon as it is picked. */
    public string $colorScheme = 'default';

    /** A ConsoleSort value: how the sidebar and the Consoles page order consoles. */
    public string $consoleSort = 'name';

    /** A ConsoleGrouping value: what the sidebar and the Consoles page group consoles by. */
    public string $consoleGrouping = 'none';

    public function mount(): void
    {
        $this->scanlines = AppSetting::enabled(AppSetting::UI_SCANLINES);
        $this->heroTitle = (string) AppSetting::get(AppSetting::UI_HERO_TITLE);
        $this->uploads = AppSetting::enabled(AppSetting::UI_UPLOADS);
        $this->colorScheme = ColorScheme::current()->value;
        $this->consoleSort = ConsoleSort::current()->value;
        $this->consoleGrouping = ConsoleGrouping::current()->value;
    }

    public function save(): void
    {
        $this->validate([
            'heroTitle' => ['required', 'in:logo,text'],
            'colorScheme' => ['required', Rule::enum(ColorScheme::class)],
            'consoleSort' => ['required', Rule::enum(ConsoleSort::class)],
            'consoleGrouping' => ['required', Rule::enum(ConsoleGrouping::class)],
        ]);

        AppSetting::put(AppSetting::UI_SCANLINES, $this->scanlines);
        AppSetting::put(AppSetting::UI_HERO_TITLE, $this->heroTitle);
        AppSetting::put(AppSetting::UI_UPLOADS, $this->uploads);
        AppSetting::put(AppSetting::UI_COLOR_SCHEME, $this->colorScheme);
        AppSetting::put(AppSetting::UI_CONSOLE_SORT, $this->consoleSort);
        AppSetting::put(AppSetting::UI_CONSOLE_GROUPING, $this->consoleGrouping);

        // Tells the preview the pick is kept, so leaving the page no longer
        // puts the old scheme back.
        $this->dispatch('color-scheme-saved', scheme: $this->colorScheme);

        if ($this->onboarding) {
            $this->redirectRoute('onboarding.step', ['step' => 'scraping'], navigate: true);

            return;
        }

        Flux::toast(variant: 'success', text: __('UI settings saved.'));
    }
}; ?>

<section class="w-full">
    @unless ($onboarding)
        @include('partials.settings-heading')
    @endunless

    <x-settings.layout :show-tabs="! $onboarding" :heading="__('UI')" :subheading="__('How retroBITE looks, independent of what it fetches')">
        {{-- Outside the form, bound back to it by id, as on the Media screen. --}}
        <x-slot name="actions">
            <x-settings.save form="interface-settings" :onboarding="$onboarding" />
        </x-slot>

        <form id="interface-settings" wire:submit="save" class="flex flex-col gap-6">
            {{-- Picking a scheme repaints the page at once by writing it onto
                 <html>; Save keeps it. Leaving unsaved puts the stored one
                 back, since wire:navigate carries <html> over untouched. Each
                 swatch is a small mock screen wearing its own scheme through
                 data-scheme, whatever the page around it wears. --}}
            <x-settings.card
                x-data="{ saved: {{ Illuminate\Support\Js::from($colorScheme) }} }"
                x-effect="document.documentElement.dataset.scheme = $wire.colorScheme"
                x-on:color-scheme-saved.window="saved = $event.detail.scheme"
                x-on:livewire:navigating.document="document.documentElement.dataset.scheme = saved"
            >
                <x-settings.choice-row
                    :title="__('Color scheme')"
                    :description="__('Recolors the whole interface and the logo. Applies to everybody who signs in, and to the sign-in page itself.')"
                >
                    <div role="radiogroup" aria-label="{{ __('Color scheme') }}" class="grid w-full grid-cols-2 gap-2 sm:grid-cols-5">
                        @foreach (ColorScheme::cases() as $scheme)
                            <button
                                type="button"
                                role="radio"
                                aria-checked="{{ $colorScheme === $scheme->value ? 'true' : 'false' }}"
                                wire:click="$set('colorScheme', '{{ $scheme->value }}')"
                                @class([
                                    'flex cursor-pointer flex-col gap-2.5 rounded-lg border p-2.5 text-xs transition-colors focus-ring',
                                    'border-accent-tint/55 bg-accent-tint/15 text-accent' => $colorScheme === $scheme->value,
                                    'border-line-input text-fg-dim hover:bg-hover hover:text-fg' => $colorScheme !== $scheme->value,
                                ])
                            >
                                {{-- Only the named tokens: a nested scheme does not
                                     recompute the zinc ramp or the shadows, so no
                                     flux: component and no shadow-* in here. --}}
                                <span data-scheme="{{ $scheme->value }}" aria-hidden="true" class="block w-full overflow-hidden rounded-md border border-line bg-ground text-start">
                                    <span class="flex items-center gap-2 border-b border-line bg-sunken px-2 py-1.5">
                                        <x-logo class="block h-auto w-7 shrink-0" />
                                        <span class="h-1 w-8 rounded-full bg-accent-tint/40"></span>
                                        <span class="h-1 w-5 rounded-full bg-line-bright"></span>
                                    </span>

                                    <span class="flex flex-col gap-1.5 bg-surface p-2">
                                        <span class="block truncate text-xs leading-tight font-medium text-fg-bright">retroBITE</span>
                                        <span class="block truncate text-[10px] leading-tight text-fg-dim">{{ __('SNES · 1995') }}</span>

                                        <span class="block h-1 rounded-full bg-line-input">
                                            <span class="block h-full w-3/5 rounded-full bg-accent"></span>
                                        </span>

                                        <span class="flex items-center gap-1">
                                            <x-badge tone="accent" fill>{{ __('Play') }}</x-badge>
                                            <x-badge>{{ __('Edit') }}</x-badge>
                                            <span class="ms-auto size-1.5 rounded-full bg-danger"></span>
                                        </span>
                                    </span>
                                </span>

                                <span class="flex items-center justify-center gap-1.5">
                                    <span data-scheme="{{ $scheme->value }}" class="size-2 rounded-full bg-accent"></span>
                                    {{ $scheme->label() }}
                                </span>
                            </button>
                        @endforeach
                    </div>
                </x-settings.choice-row>
            </x-settings.card>

            <x-settings.card>
                <x-settings.choice-row
                    :title="__('Game page title')"
                    :description="__('What heads a game\'s page: the provider\'s logo, or the title as text. A game with no logo shows its title either way.')"
                >
                    <x-pill-choice model="heroTitle" :value="$heroTitle" :label="__('Game page title')"
                                   :options="['logo' => __('Logo'), 'text' => __('Text')]" />
                </x-settings.choice-row>
            </x-settings.card>

            <x-settings.card>
                <x-settings.choice-row
                    :title="__('Console order')"
                    :description="__('How the sidebar and the Consoles page list your consoles: by name, oldest first, or by how many games each holds.')"
                >
                    <x-pill-choice model="consoleSort" :value="$consoleSort" :label="__('Console order')"
                                   :options="App\Enums\ConsoleSort::options()" />
                </x-settings.choice-row>
            </x-settings.card>

            <x-settings.card>
                <x-settings.choice-row
                    :title="__('Group consoles by')"
                    :description="__('Headings in the sidebar and sections on the Consoles page. The order above applies inside each group.')"
                >
                    <x-pill-choice model="consoleGrouping" :value="$consoleGrouping" :label="__('Group consoles by')"
                                   :options="App\Enums\ConsoleGrouping::options()" />
                </x-settings.choice-row>
            </x-settings.card>

            <x-settings.card>
                <flux:switch wire:model="scanlines"
                             :label="__('CRT scanlines')"
                             :description="__('Lays the faint horizontal banding of a tube over key art and the sign-in backdrop. Part of the look rather than decoration you can ignore — turn it off for a flat presentation.')" />
            </x-settings.card>

            <x-settings.card>
                <flux:switch wire:model="uploads"
                             :label="__('ROM uploads')"
                             :description="__('Adds Upload files to each console\'s Actions menu. Files land in the folders that console\'s layout reads games from.')" />
            </x-settings.card>
        </form>
    </x-settings.layout>
</section>
