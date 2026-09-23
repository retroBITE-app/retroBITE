<?php

use App\Enums\MediaKind;
use App\Models\AppSetting;
use App\Support\MediaRegions;
use App\Support\MediaTypes;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Media settings')] class extends Component
{
    /** @var array<string, bool> keyed by provider media type */
    public array $enabled = [];

    public bool $autoQueue = true;

    /** '' means no preference. */
    public string $region = '';

    public function mount(): void
    {
        $chosen = MediaTypes::enabled();

        $this->enabled = Collection::make(MediaTypes::offered())
            ->mapWithKeys(fn (string $type): array => [$type => in_array($type, $chosen, true)])
            ->all();

        $this->autoQueue = AppSetting::enabled(AppSetting::AUTO_QUEUE_MEDIA_SCRAPE);
        $this->region = MediaRegions::preferred();
    }

    /**
     * The catalogue, grouped, so two dozen opaque names read as five lists.
     *
     * @return array<int, array{label: string, types: array<int, string>}>
     */
    #[Computed]
    public function groups(): array
    {
        return MediaTypes::grouped();
    }

    /**
     * Region codes with a label, preference first.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function regions(): array
    {
        return MediaRegions::labels();
    }

    /**
     * Which display role each provider type can stand in for, so the list
     * reads as something other than two dozen opaque names.
     *
     * @return Collection<string, string>
     */
    #[Computed]
    public function roles(): Collection
    {
        return collect(MediaKind::cases())
            ->flatMap(fn (MediaKind $kind) => collect($kind->screenScraperTypes())
                ->mapWithKeys(fn (string $type) => [$type => $kind->label()]));
    }

    public function save(): void
    {
        MediaTypes::remember(Collection::make($this->enabled)->filter()->keys()->all());

        AppSetting::put(AppSetting::AUTO_QUEUE_MEDIA_SCRAPE, $this->autoQueue);
        AppSetting::put(AppSetting::MEDIA_REGION, $this->region);

        Flux::toast(variant: 'success', text: __('Media settings saved.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Media')" :subheading="__('What artwork retroBite fetches, and when')">
        {{-- Outside the form, bound back to it by id: the header row is where
             the control belongs, and the whole pane sits between them. --}}
        <x-slot name="actions">
            <flux:button variant="primary" type="submit" form="media-settings">{{ __('Save') }}</flux:button>
        </x-slot>

        <form id="media-settings" wire:submit="save" class="grid grid-cols-1 gap-6 lg:grid-cols-12">
            {{-- The short cards stack in their own column so the tall one
                 beside them has something to sit next to. --}}
            <div class="flex flex-col gap-6 lg:col-span-5">
                <div class="rounded-xl border border-line bg-surface p-5">
                    <flux:switch wire:model="autoQueue"
                                 :label="__('Fetch artwork automatically')"
                                 :description="__('Queues a download as soon as a game is identified. Turn it off to fetch on demand instead.')" />
                </div>

                <div class="rounded-xl border border-line bg-surface p-5">
                    <flux:select wire:model="region" :label="__('Preferred region')"
                                 :description="__('Artwork often exists for several regions. One copy is kept — this one when it exists, otherwise World, Europe, the United States and Japan in that order.')">
                        <flux:select.option value="">{{ __('No preference') }}</flux:select.option>
                        @foreach ($this->regions as $code => $label)
                            <flux:select.option value="{{ $code }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            <div class="rounded-xl border border-line bg-surface p-5 lg:col-span-7">
                <p class="kicker mb-1 text-fg-faint">{{ __('Types to fetch') }}</p>
                <p class="mb-4 text-sm text-fg-soft">
                    {{ __('Each one switched on is another download per game, at 128 KB/s on a free ScreenScraper account.') }}
                </p>

                @foreach ($this->groups as ['label' => $label, 'types' => $types])
                    <p class="kicker mt-5 mb-2 text-fg-dim first:mt-0">{{ __($label) }}</p>

                    <div class="grid gap-x-8 gap-y-3 sm:grid-cols-2">
                        @foreach ($types as $type)
                            <label wire:key="type-{{ $type }}" class="flex cursor-pointer items-center gap-3">
                                <flux:checkbox wire:model="enabled.{{ $type }}" />
                                <span class="min-w-0 flex-1">
                                    <span class="font-mono text-sm text-fg-bright">{{ $type }}</span>
                                    @if ($this->roles->has($type))
                                        <span class="ml-2 text-xs text-fg-faint">{{ $this->roles[$type] }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                @endforeach
            </div>

        </form>
    </x-settings.layout>
</section>
