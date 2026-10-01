{{--
    One numbered step of a settings form: the number in the onboarding rail's
    ring, a title, and a line saying what the step is for. The step's fields
    go in the slot, indented under the title so the numbers read as a column.
--}}
@props([
    'number',
    'title',
    'hint' => null,
])

<div {{ $attributes->class('flex gap-3.5') }}>
    <span class="mt-px grid size-5 shrink-0 place-items-center rounded-full border border-accent-tint/55 bg-accent-tint/15 font-mono text-[10px] text-accent">
        {{ $number }}
    </span>

    <div class="min-w-0 flex-1">
        <p class="text-sm text-fg-bright">{{ $title }}</p>

        @if ($hint !== null)
            <p class="mt-0.5 text-sm text-fg-faint">{{ $hint }}</p>
        @endif

        <div class="mt-3">
            {{ $slot }}
        </div>
    </div>
</div>
