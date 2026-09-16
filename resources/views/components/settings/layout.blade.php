@props(['heading' => '', 'subheading' => ''])

@php
    $tabs = [
        ['label' => __('Profile'), 'route' => 'profile.edit', 'active' => request()->routeIs('profile.*')],
        ['label' => __('Security'), 'route' => 'security.edit', 'active' => request()->routeIs('security.*')],
    ];
@endphp

<div class="mb-6 flex gap-5.5 overflow-x-auto border-b border-raised">
    @foreach ($tabs as $tab)
        <a
            href="{{ route($tab['route']) }}"
            wire:navigate
            @class([
                'shrink-0 cursor-pointer pb-2.75 text-sm whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep',
                'text-fg-bright shadow-underline' => $tab['active'],
                'text-fg-muted hover:text-fg-soft' => ! $tab['active'],
            ])
        >
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>

@if (filled($heading) || filled($subheading))
    <div class="mb-5">
        @if (filled($heading))
            <p class="text-base text-fg-bright">{{ $heading }}</p>
        @endif

        @if (filled($subheading))
            <p class="mt-0.5 text-sm text-fg-faint">{{ $subheading }}</p>
        @endif
    </div>
@endif

<div class="w-full max-w-lg" data-settings-fields>
    {{ $slot }}
</div>
