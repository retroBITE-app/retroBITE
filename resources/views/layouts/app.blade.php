{{--
    `bleed` is for pages that paint to the edges themselves — the game detail
    hero runs its backdrop full width. flux:main cannot simply be overridden:
    it hard-adds `p-6 lg:p-8` and merges call-site classes after its own, so
    `p-0` and `p-6` both land in the attribute and Tailwind's stylesheet order,
    not the class order, decides. It is not rendered at all instead.

    data-flux-main is load-bearing: flux.css keys the sidebar/main grid off
    `*:has(> [data-flux-main])`.
--}}
<x-layouts::app.sidebar :title="$title ?? null">
    @if ($bleed ?? false)
        {{-- No max-lg:pt-16 here on purpose: a bleeding page starts at the very
             top and clears the floating hamburger itself. --}}
        <div class="[grid-area:main]" data-flux-main>
            {{ $slot }}
        </div>
    @else
        <flux:main class="max-lg:pt-16">
            {{ $slot }}
        </flux:main>
    @endif
</x-layouts::app.sidebar>
