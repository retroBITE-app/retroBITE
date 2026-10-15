@props(['unlocked', 'possible', 'hardcore' => false])

{{-- A game's achievements as a bar and its counts, no points: the same on a
     card and in a list row, so the two views agree. The caller sets the width. --}}
@php($percent = $possible > 0 ? $unlocked / $possible * 100 : 0)

<div {{ $attributes->class('flex items-center gap-2') }}>
    <x-progress-bar :percent="$percent" class="flex-1" />

    <x-fraction
        :value="$unlocked"
        :of="$possible"
        class="shrink-0 font-mono text-xs text-fg-dim"
        title="{{ $hardcore ? __('Hardcore achievements') : __('Achievements') }}"
    />
</div>
