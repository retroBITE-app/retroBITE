@props([
    'title' => null,
    // A cached artwork URL, or null on a host with nothing scraped yet.
    'backdrop' => null,
    // `[['value' => …, 'label' => …], …]`, or empty to show nothing.
    'stats' => [],
])

<x-layouts::auth.simple :title="$title" :backdrop="$backdrop" :stats="$stats">
    {{ $slot }}
</x-layouts::auth.simple>
