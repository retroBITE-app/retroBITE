@props(['content'])

{{-- What a game's files hold, where it is not the game itself: DLC, an
     update. App\Enums\FileContent, or its value as a list's subquery reads
     it; nothing for a game or an unknown. --}}
@php
    $content = $content instanceof App\Enums\FileContent ? $content : App\Enums\FileContent::tryFrom((string) $content);
@endphp

@if ($content?->extra())
    <x-badge tone="accent" :icon="$content->icon()" :tip="$content->hint()" {{ $attributes }}>{{ $content->label() }}</x-badge>
@endif
