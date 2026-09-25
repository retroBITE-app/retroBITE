@props([
    'title' => null,
    // A cached artwork URL, or null on a host with nothing scraped yet.
    'backdrop' => null,
    // `[['value' => …, 'label' => …], …]`, or empty to show nothing.
    'stats' => [],
    // See layouts/auth/simple.
    'wide' => false,
])

<x-layouts::auth.simple :title="$title" :backdrop="$backdrop" :stats="$stats" :wide="$wide">
    {{ $slot }}
</x-layouts::auth.simple>
