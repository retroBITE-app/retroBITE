<?php

use App\Conversion\Tools;
use Illuminate\Support\Arr;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The Tool paths button and its panel: which command-line tools are here,
 * where, and at which version against the one pinned. On Tools → Conversion
 * and Tools → Decrypt, each held to the tools its own page runs.
 */
new class extends Component
{
    /** @var list<string>|null tool keys to list; null for every tool */
    #[Locked]
    public ?array $only = null;

    /**
     * The tools this page runs, as the report has them: the panel's rows.
     *
     * @return list<array{key: string, label: string, configured: string, path: string|null, pinned: string, found: string|null, licence: string, formats: list<string>}>
     */
    #[Computed]
    public function tools(): array
    {
        return collect(Tools::report())
            ->filter(function (array $tool): bool {
                return $this->only === null || in_array(Arr::get($tool, 'key'), $this->only, true);
            })
            ->values()
            ->all();
    }

    /** Check again after a tool path changed: versions are cached. */
    public function refreshTools(): void
    {
        Tools::forget();

        unset($this->tools);
    }
}; ?>

<div>
    <flux:modal.trigger name="tool-paths">
        <flux:button size="sm" variant="ghost" icon="wrench">{{ __('Tool paths') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal name="tool-paths" class="w-full max-w-4xl">
        <div class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Tool paths') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('The command-line tools this page runs. They ship in the web image, pinned; set CHDMAN_PATH, PS3DEC_PATH and the like in .env to use others. A missing tool turns off only what needs it.') }}
                </flux:text>
            </div>

            <div class="overflow-x-auto rounded-lg border border-line">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-line bg-sunken">
                        <tr>
                            <th class="kicker px-3 py-2 font-normal text-fg-faint">{{ __('Tool') }}</th>
                            <th class="kicker px-3 py-2 font-normal text-fg-faint">{{ __('Path') }}</th>
                            <th class="kicker px-3 py-2 font-normal text-fg-faint">{{ __('Formats') }}</th>
                            <th class="kicker px-3 py-2 font-normal text-fg-faint">{{ __('Pinned / found') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($this->tools as ['key' => $key, 'label' => $label, 'configured' => $configured, 'path' => $path, 'pinned' => $pinned, 'found' => $found, 'licence' => $licence, 'formats' => $formats])
                            <tr wire:key="tool-{{ $key }}">
                                <td class="px-3 py-2">
                                    <span class="text-fg-soft">{{ $label }}</span>
                                    <span class="block text-xs text-fg-faint">{{ $licence }}</span>
                                </td>
                                <td class="px-3 py-2 font-mono text-xs">
                                    @if ($path !== null)
                                        <span class="text-fg-soft">{{ $path }}</span>
                                    @else
                                        <span class="text-danger">{{ __('missing') }}</span>
                                        <span class="block text-fg-faint">{{ $configured }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 font-mono text-xs">
                                    @forelse ($formats as $format)
                                        <span class="block whitespace-nowrap text-fg-soft">{{ $format }}</span>
                                    @empty
                                        <span class="text-fg-faint">{{ __('none yet') }}</span>
                                    @endforelse
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">
                                    <span class="text-fg-muted">{{ $pinned }}</span>
                                    <span class="text-fg-faint">/</span>
                                    <span @class(['text-fg-soft' => $found === $pinned, 'text-warn' => $found !== null && $found !== $pinned, 'text-fg-faint' => $found === null])>{{ $found ?? '—' }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refreshTools">{{ __('Check again') }}</flux:button>
                <flux:modal.close>
                    <flux:button size="sm" variant="ghost">{{ __('Close') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
