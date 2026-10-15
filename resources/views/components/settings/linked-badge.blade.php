@props(['linked' => false])

{{-- Whether an account card's credentials are on record, beside its title. --}}
@if ($linked)
    <x-badge tone="accent" fill {{ $attributes }}>{{ __('Linked') }}</x-badge>
@else
    <x-badge tone="muted" {{ $attributes }}>{{ __('Not linked') }}</x-badge>
@endif
