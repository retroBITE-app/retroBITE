<?php

use App\Enums\MediaKind;
use App\Models\AppSetting;
use App\Models\MediaTypePreference;
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

    public function mount(): void
    {
        $this->enabled = MediaTypePreference::query()
            ->orderBy('media_type')
            ->pluck('enabled', 'media_type')
            ->map(fn ($value) => (bool) $value)
            ->all();

        $this->autoQueue = AppSetting::enabled(AppSetting::AUTO_QUEUE_MEDIA_SCRAPE, true);
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
        foreach ($this->enabled as $type => $on) {
            MediaTypePreference::query()->where('media_type', $type)->update(['enabled' => (bool) $on]);
        }

        AppSetting::put(AppSetting::AUTO_QUEUE_MEDIA_SCRAPE, $this->autoQueue);

        Flux::toast(variant: 'success', text: __('Media settings saved.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Media')" :subheading="__('What artwork retroBite fetches, and when')">
        <form wire:submit="save" class="flex flex-col gap-6">
            <div class="rounded-xl border border-line bg-surface p-5">
                <flux:switch wire:model="autoQueue"
                             :label="__('Fetch artwork automatically')"
                             :description="__('Queues a download as soon as a game is identified. Turn it off to fetch on demand instead.')" />
            </div>

            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="kicker mb-1 text-fg-faint">{{ __('Types to fetch') }}</p>
                <p class="mb-4 text-sm text-fg-soft">
                    {{ __('Each one switched on is another download per game, at 128 KB/s on a free ScreenScraper account.') }}
                </p>

                <div class="grid gap-x-8 gap-y-3 sm:grid-cols-2">
                    @foreach ($enabled as $type => $on)
                        <label wire:key="type-{{ $type }}" class="flex items-center gap-3">
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
            </div>

            <div>
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </x-settings.layout>
</section>
