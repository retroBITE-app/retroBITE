@props(['form', 'onboarding' => false, 'label' => null])

{{-- A settings screen's Save, in the header row outside its form and bound
     back to it by id. In the first-run wizard saving also moves on, so it says so. --}}
<flux:button variant="primary" type="submit" form="{{ $form }}" {{ $attributes }}>
    {{ $label ?? ($onboarding ? __('Continue') : __('Save')) }}
</flux:button>
