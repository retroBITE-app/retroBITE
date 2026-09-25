{{--
    First-run setup, one step per visit, with where it has got to in a row
    under the logo. Every step but the first is a settings screen embedded
    with `onboarding` on, which drops its tabs and makes its Save move on;
    EnsureOnboarded decides who may be on which step.

    Earlier steps read as done rather than as links: the account step cannot
    be gone back to once the account exists. Below sm only the current step
    is named — four names abreast do not fit a phone.
--}}
@php
    $steps = [
        'account' => __('Account'),
        'interface' => __('Interface'),
        'scraping' => __('Scraping'),
        'achievements' => __('Achievements'),
    ];

    $current = array_search($step, array_keys($steps), true);
@endphp

<x-layouts::auth :title="__('Set up retroBITE')" wide>
    <ol aria-label="{{ __('Setup progress') }}" class="mt-9 flex items-center justify-center gap-4 sm:gap-6">
        @foreach (array_values($steps) as $index => $label)
            <li
                @if ($index === $current) aria-current="step" @endif
                @class([
                    'kicker-sans flex items-center gap-2 uppercase',
                    'text-accent' => $index === $current,
                    'text-fg-muted' => $index < $current,
                    'text-fg-faint' => $index > $current,
                ])
            >
                <span
                    @class([
                        'grid size-5 shrink-0 place-items-center rounded-full border font-mono text-[10px]',
                        'border-accent-tint/55 bg-accent-tint/15' => $index === $current,
                        'border-line-bright' => $index !== $current,
                    ])
                >
                    @if ($index < $current)
                        <flux:icon.check variant="micro" class="size-3" />
                    @else
                        {{ $index + 1 }}
                    @endif
                </span>
                <span @class(['sm:inline', 'hidden' => $index !== $current])>{{ $label }}</span>
            </li>
        @endforeach
    </ol>

    {{-- The account step keeps a sign-in form's width; the settings screens
         are laid out as cards and take the column. --}}
    <div @class(['mt-8 w-full', 'mx-auto max-w-[372px]' => $step === 'account'])>
        @switch($step)
            @case('account')
                <livewire:onboarding.account />
                @break
            @case('interface')
                <livewire:settings.interface :onboarding="true" />
                @break
            @case('scraping')
                <livewire:settings.screenscraper :onboarding="true" />
                @break
            @case('achievements')
                <livewire:settings.retroachievements :onboarding="true" />
                @break
        @endswitch
    </div>
</x-layouts::auth>
