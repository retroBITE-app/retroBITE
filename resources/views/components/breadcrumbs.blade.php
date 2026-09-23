{{--
    Where a page sits in the library, over its hero: Consoles › Super Nintendo ›
    the game. Each step but the last is a link back up.

    The same glass chip the back button it replaces was drawn in, so the bar
    over the hero looks as it did and only says more. The last step is the one
    that truncates: the way back up is worth more than the end of a long title
    the hero is already showing in full.

    $items is a list of [label, href]; the href of the last is ignored.
--}}
@props(['items'])

<nav
    aria-label="{{ __('Breadcrumb') }}"
    {{ $attributes->class('min-w-0 rounded-lg border border-line-input bg-scrim/60 px-2.75 py-1.5 text-sm backdrop-blur-sm') }}
>
    <ol class="flex min-w-0 items-center gap-1.5">
        @foreach ($items as $index => [$label, $href])
            @php($last = $loop->last)

            <li @class(['flex items-center gap-1.5', 'min-w-0' => $last, 'shrink-0' => ! $last])>
                @if ($index > 0)
                    <flux:icon.chevron-right class="size-3 shrink-0 text-fg-faint" aria-hidden="true" />
                @endif

                @if ($last)
                    <span aria-current="page" class="truncate text-fg-bright">{{ $label }}</span>
                @else
                    <a
                        href="{{ $href }}"
                        wire:navigate
                        class="rounded-sm text-fg-soft transition-colors hover:text-fg-bright focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
                    >{{ $label }}</a>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
