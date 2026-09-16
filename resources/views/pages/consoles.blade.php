<x-layouts::app :title="__('Consoles')">
    <div class="mb-6">
        <p class="kicker mb-1.5 text-fg-faint">{{ __('Library') }}</p>
        <h1 class="text-display font-medium tracking-display text-fg-bright">{{ __('Consoles') }}</h1>
    </div>

    <div class="rounded-xl border border-dashed border-line-input px-6 py-10 text-center">
        <p class="text-sm text-fg-soft">{{ __('Every system retroBITE knows about, installed or not.') }}</p>
        <p class="mt-1 text-sm text-fg-faint">{{ __('Nothing here yet.') }}</p>
    </div>
</x-layouts::app>
