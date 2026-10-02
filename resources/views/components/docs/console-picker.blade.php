{{--
    The console a document is filed under, as chips: "No console" first, then
    the consoles in the library, and the rest of the 135 behind "Show all".

    The chips set the parent component's `console` with $set, so this only
    draws them. A console already chosen stays in view even when it is not in
    the library — an import can name one from its front matter. With nothing
    in the library yet, every console shows, as there is nothing to lead with.
    Show all is Alpine's alone: no request to see the rest of a list already
    drawn.
--}}
@props([
    'selected' => '',
    'keyPrefix' => 'console',
])

@php
    $library = App\Models\ConsoleSourceFolder::consoles()->pluck('key');
    $all = App\Resources\ConsoleResource::all();

    [$first, $rest] = $library->isEmpty()
        ? [$all, collect()]
        : $all->partition(function (App\Resources\ConsoleResource $console) use ($library, $selected): bool {
            return $library->contains($console->key) || $console->key === $selected;
        });

    $chip = 'cursor-pointer rounded-lg border px-2.5 py-1 font-mono text-xs uppercase transition-colors';
    $on = 'border-accent-tint/55 bg-accent-tint/10 text-accent';
    $off = 'border-line-input text-fg-dim hover:bg-hover hover:text-fg';
@endphp

<div x-data="{ all: false }" class="flex flex-wrap gap-1.5">
    <button
        type="button"
        wire:key="{{ $keyPrefix }}-none"
        wire:click="$set('console', '')"
        @class([$chip, 'normal-case', $on => $selected === '', $off => $selected !== ''])
    >{{ __('No console') }}</button>

    @foreach ($first as $option)
        <button
            type="button"
            wire:key="{{ $keyPrefix }}-{{ $option->key }}"
            wire:click="$set('console', @js($selected === $option->key ? '' : $option->key))"
            title="{{ $option->name }}"
            @class([$chip, $on => $selected === $option->key, $off => $selected !== $option->key])
        >{{ $option->key }}</button>
    @endforeach

    @if ($rest->isNotEmpty())
        @foreach ($rest as $option)
            <button
                type="button"
                x-show="all"
                x-cloak
                wire:key="{{ $keyPrefix }}-{{ $option->key }}"
                wire:click="$set('console', @js($option->key))"
                title="{{ $option->name }}"
                class="{{ $chip }} {{ $off }}"
            >{{ $option->key }}</button>
        @endforeach

        <button
            type="button"
            x-on:click="all = ! all"
            class="cursor-pointer rounded-lg border border-dashed border-line-bright px-2.5 py-1 text-xs text-fg-dim transition-colors hover:bg-hover hover:text-fg"
            x-text="all ? @js(__('Show fewer')) : @js(__('Show all consoles'))"
        >{{ __('Show all consoles') }}</button>
    @endif
</div>
