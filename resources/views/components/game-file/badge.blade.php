@props(['tip', 'tone' => null, 'dashed' => false, 'warn' => false, 'archived' => false])

{{-- One badge on a file card, named on hover: with no column heading
     above it, "Game" or "RVZ" alone does not say what it is. x-badge's sm
     size, on the sunken ground unless it warns; an archived row's text is
     quieter, and so is its warning. --}}
@php
    $tone ??= match (true) {
        $warn => 'warn',
        $archived => 'muted',
        default => 'neutral',
    };
@endphp

<x-badge
    size="sm"
    :tone="$tone"
    :dashed="$dashed"
    :tip="$tip"
    :attributes="$attributes->class([
        'bg-sunken' => $tone !== 'warn',
        'opacity-70' => $tone === 'warn' && $archived,
    ])"
>{{ $slot }}</x-badge>
