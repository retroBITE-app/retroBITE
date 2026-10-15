@props([
    // The live.system() `what`, or a list of them, each running the same call.
    'signal',
    // The Livewire method to run on a signal, `$refresh` included.
    'call',
    // Milliseconds: also run it once after this, for work that hangs silently.
    'timeout' => null,
    // The element to render, for a root that must be a <section>.
    'as' => 'div',
])

{{--
    Runs one of the component's methods when the server says something moved,
    instead of polling for it. A reconnect runs it too (echo.js), in case a
    signal went by while the socket was down. Rendering it starts the
    listener; removing it (an @if turning false, leaving the page) stops it.
--}}
@php
    $signals = array_values((array) $signal);
@endphp

<{{ $as }}
    x-data="{
        stop: null,
        timer: null,
        init() {
            const run = () => this.$wire[@js($call)]();
            const stops = @js($signals).map((what) => live.system(what, run));

            this.stop = () => stops.forEach((stop) => stop?.());
            @if ($timeout !== null)
                this.timer = setTimeout(run, @js((int) $timeout));
            @endif
        },
        destroy() {
            this.stop?.();
            clearTimeout(this.timer);
        },
    }"
    {{ $attributes }}
>{{ $slot }}</{{ $as }}>
