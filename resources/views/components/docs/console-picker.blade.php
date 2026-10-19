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
@endphp

<div x-data="{ all: false }" class="flex flex-wrap gap-1.5">
    <x-chip :active="$selected === ''" wire:key="{{ $keyPrefix }}-none" wire:click="$set('console', '')">{{ __('No console') }}</x-chip>

    @foreach ($first as $option)
        <x-chip
            mono
            :active="$selected === $option->key"
            wire:key="{{ $keyPrefix }}-{{ $option->key }}"
            wire:click="$set('console', {{ Js::from($selected === $option->key ? '' : $option->key) }})"
            :title="$option->name"
            class="uppercase"
        >{{ $option->key }}</x-chip>
    @endforeach

    @if ($rest->isNotEmpty())
        @foreach ($rest as $option)
            <x-chip
                mono
                x-show="all"
                x-cloak
                wire:key="{{ $keyPrefix }}-{{ $option->key }}"
                wire:click="$set('console', {{ Js::from($option->key) }})"
                :title="$option->name"
                class="uppercase"
            >{{ $option->key }}</x-chip>
        @endforeach

        <x-chip
            dashed
            x-on:click="all = ! all"
            x-text="all ? {{ Js::from(__('Show fewer')) }} : {{ Js::from(__('Show all consoles')) }}"
        >{{ __('Show all consoles') }}</x-chip>
    @endif
</div>
