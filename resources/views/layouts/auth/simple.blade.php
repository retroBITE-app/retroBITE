@props([
    'title' => null,
    // A cached artwork URL, or null on a host with nothing scraped yet.
    'backdrop' => null,
    // `[['value' => …, 'label' => …], …]`, or empty to show nothing.
    'stats' => [],
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen antialiased">

        @if (! empty($backdrop))
            <div class="fixed inset-0 bg-cover bg-[position:50%_22%]" style="background-image: url('{{ $backdrop }}')"></div>
        @endif

        <div
            class="fixed inset-0"
            style="
                background: radial-gradient(
                    ellipse 75% 65% at 50% 45%,
                    color-mix(in srgb, var(--color-ground) 88%, transparent) 0%,
                    color-mix(in srgb, var(--color-ground) 62%, transparent) 55%,
                    color-mix(in srgb, var(--color-ground) 28%, transparent) 100%
                );
            "
        ></div>

        @if (config('settings.interface.scanlines'))
            <div class="scanlines fixed inset-0"></div>
        @endif

        <div
            class="pointer-events-none fixed inset-0"
            style="
                background: linear-gradient(
                    0deg,
                    color-mix(in srgb, var(--color-ground) 88%, transparent) 0%,
                    color-mix(in srgb, var(--color-ground) 40%, transparent) 22%,
                    transparent 45%
                );
            "
        ></div>

        <div class="relative flex min-h-screen flex-col px-5 py-12">
            <div class="relative z-10 flex flex-1 items-center justify-center">
                <div class="flex w-full max-w-[372px] flex-col">
                    <a href="{{ route('login') }}" wire:navigate>
                        <img src="/images/logo.webp" alt="{{ config('app.name') }}" class="mx-auto block h-auto w-[148px]" />
                    </a>

                    {{ $slot }}
                </div>
            </div>

            {{-- Pinned to the bottom by being the last row of a column layout
                 rather than by `fixed`: on a short window it gets pushed down
                 and scrolls with the form instead of sitting on top of it. --}}
            @if (! empty($stats))
                <div class="relative z-10 mt-12 shrink-0 text-center">
                    <p class="kicker-sans text-fg-dim uppercase">{{ __('On this host') }}</p>

                    <dl class="mt-3 flex flex-wrap justify-center gap-x-9 gap-y-3">
                        @foreach ($stats as ['value' => $value, 'label' => $label])
                            <div>
                                <dd class="font-mono text-xl text-fg">{{ $value }}</dd>
                                <dt class="mt-0.5 text-xs text-fg-muted">{{ $label }}</dt>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
