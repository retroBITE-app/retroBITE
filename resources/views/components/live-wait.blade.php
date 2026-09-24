{{--
    A "waiting for…" banner that checks when the work is done instead of
    polling for it.

    It runs the component's own check method — the one the poll used to call
    every three seconds — on three occasions only:

    - a GameUpdated signal for this game whose `what` is one of $on, or
      `failed` (the job gave up), or `reconnect` (the socket dropped and came
      back, and a signal may have been sent in between);
    - once, when $timeout seconds have passed since $since, so the check's
      existing give-up branch still runs for a job that hangs without failing.

    The check decides everything, as before; this only decides when to ask.
    Rendered only while the wait is on, so leaving the page or finishing the
    wait tears the listener and the timer down with it.
--}}
@props([
    'game',
    'on',
    'check',
    'since' => null,
    'timeout',
])

@php
    // One second past the deadline, so the check sees it has passed rather
    // than landing a hair before it on a clock that ticks in whole seconds.
    $deadlineMs = max(0, ((int) ($since ?? now()->timestamp) + (int) $timeout - now()->timestamp + 1) * 1000);
    $whats = array_values(array_unique([...(array) $on, 'failed', 'reconnect']));
@endphp

<div
    x-data="{
        stop: null,
        timer: null,
        init() {
            const run = () => this.$wire.$call(@js($check));
            const whats = @js($whats);

            this.stop = live.game(@js((int) $game), (what) => whats.includes(what) && run());
            this.timer = setTimeout(run, @js($deadlineMs));
        },
        destroy() {
            this.stop?.();
            clearTimeout(this.timer);
        },
    }"
    {{ $attributes }}
>
    {{ $slot }}
</div>
