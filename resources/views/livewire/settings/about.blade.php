<?php

use App\Services\AboutService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('About')] class extends Component
{
    /**
     * The tab's content, read fresh on each render.
     *
     * @return array{version: string, build: list<array{key: string, value: string}>, website: array{icon: string, label: string, url: string}, links: list<array{icon: string, label: string, url: string}>, credits: list<array{icon: string, name: string, role: string}>}
     */
    #[Computed]
    public function about(): array
    {
        return app(AboutService::class)->payload();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    @php(['version' => $version, 'build' => $build, 'website' => $website, 'links' => $links, 'credits' => $credits] = $this->about)

    <x-settings.layout :heading="__('About')" :subheading="__('What this install is, what it runs on, and who made it possible')">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="overflow-hidden rounded-xl border border-line bg-surface">
                <div class="border-b border-line px-4.5 py-4">
                    <p class="kicker text-fg-faint">{{ __('Version') }}</p>
                    <p class="mt-1.5 font-mono text-xl text-fg-bright">{{ $version }}</p>
                </div>

                <dl>
                    @foreach ($build as ['key' => $key, 'value' => $value])
                        <div class="flex justify-between gap-3 border-b border-line/70 px-4.5 py-2.5 text-sm">
                            <dt class="text-fg-dim">{{ __($key) }}</dt>
                            <dd class="truncate font-mono text-fg-soft">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                <div class="px-4.5 py-4">
                    <x-link-card :icon="Arr::get($website, 'icon')" :label="__(Arr::get($website, 'label'))" :url="Arr::get($website, 'url')" />
                </div>
            </div>

            <div class="flex flex-col gap-2">
                @foreach ($links as ['icon' => $icon, 'label' => $label, 'url' => $url])
                    <x-link-card :icon="$icon" :label="__($label)" :url="$url" />
                @endforeach
            </div>
        </div>

        <div class="mt-7.5">
            <p class="kicker text-fg-faint">{{ __('Built on') }}</p>

            <ul class="mt-4.5 grid gap-px overflow-hidden rounded-xl border border-line bg-line sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($credits as ['icon' => $icon, 'name' => $name, 'role' => $role])
                    <li class="flex items-start gap-3 bg-surface px-4.5 py-4 sm:last:odd:col-span-2 lg:last:odd:col-span-1">
                        <flux:icon :icon="$icon" variant="mini" class="mt-px size-4.25 shrink-0 text-fg-muted" />
                        <div class="min-w-0">
                            <p class="text-sm text-fg-soft">{{ $name }}</p>
                            <p class="mt-0.75 text-xs leading-normal text-pretty text-fg-dim">{{ __($role) }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </x-settings.layout>
</section>
