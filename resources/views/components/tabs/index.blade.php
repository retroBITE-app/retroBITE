{{-- The app's tab strip: Settings, a game's page, Tools → Conversion and
     Decrypt. One row that scrolls sideways when the tabs outrun the screen,
     kept on its current tab by tabStrip (resources/js/tab-strip.js). The tabs
     are <x-tabs.item>s. --}}
<div x-data="tabStrip" {{ $attributes->class('tab-strip flex gap-5.5 overflow-x-auto border-b border-raised') }}>
    {{ $slot }}
</div>
