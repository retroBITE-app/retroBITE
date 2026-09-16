<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="max-lg:pt-16">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
