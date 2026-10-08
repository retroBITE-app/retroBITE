<?php

use App\Conversion\ConversionQueue;
use App\Conversion\Converters;
use App\Enums\ConversionStatus;
use App\Models\Conversion;
use App\Support\Console;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * The conversion queue: what is waiting, running and done, with the tool's
 * own log a click away. Every conversion, on every console — or one
 * converter's alone, for a tool with a page of its own such as Decrypt.
 *
 * What the worker is doing arrives as a `conversion` signal, and a picker
 * beside it that queued something says so; either re-renders it.
 */
new class extends Component
{
    /**
     * The rows whose log is open. Only these carry their log to the page:
     * a log is up to 64 KB, and the list re-renders every second while a
     * conversion runs.
     *
     * @var list<int>
     */
    #[Locked]
    public array $openLogs = [];

    /** One converter's conversions only, by key; null for every one. */
    #[Locked]
    public ?string $converter = null;

    /**
     * The newest conversions first, the running ones above everything —
     * without their logs, only whether each has one.
     *
     * @return Collection<int, Conversion>
     */
    #[Computed]
    public function queue(): Collection
    {
        return Conversion::query()
            ->when($this->converter !== null, function (Builder $query): void {
                $query->where('converter', $this->converter);
            })
            ->select([
                'id', 'console', 'converter', 'label', 'status', 'progress', 'eta_at', 'source_bytes', 'output_bytes',
                'failure', 'cancel_requested_at', 'started_at', 'finished_at',
            ])
            ->selectRaw('log is not null as has_log')
            ->orderByRaw('case when status in (?, ?) then 0 when status = ? then 1 else 2 end', [
                ConversionStatus::Running->value, ConversionStatus::Verifying->value, ConversionStatus::Queued->value,
            ])
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    /**
     * The open rows' logs, by conversion id, read fresh on every render so an
     * open log follows the tool as it runs.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function logs(): array
    {
        if ($this->openLogs === []) {
            return [];
        }

        /** @var array<int, string> */
        return Conversion::query()->whereKey($this->openLogs)->pluck('log', 'id')->filter()->all();
    }

    /** Open a row's log, or close it. */
    public function toggleLog(int $id): void
    {
        $this->openLogs = in_array($id, $this->openLogs, true)
            ? array_values(array_diff($this->openLogs, [$id]))
            : [...$this->openLogs, $id];

        unset($this->logs);
    }

    public function cancel(int $id, ConversionQueue $queue): void
    {
        $conversion = Conversion::query()->find($id);

        if ($conversion !== null) {
            $queue->cancel($conversion);
        }

        unset($this->queue);
    }

    public function retry(int $id, ConversionQueue $queue): void
    {
        $conversion = Conversion::query()->find($id);

        if ($conversion !== null) {
            $queue->retry($conversion);
        }

        unset($this->queue);
    }

    public function remove(int $id, ConversionQueue $queue): void
    {
        $conversion = Conversion::query()->find($id);

        if ($conversion !== null) {
            $queue->remove($conversion);
        }

        unset($this->queue);
    }

    public function clearFinished(ConversionQueue $queue): void
    {
        $queue->clearFinished($this->converter);

        unset($this->queue);
    }

    /**
     * What the label over a conversion's bar says. Running: how long it has
     * left at the pace it has kept, counted down on every render rather than
     * only when the runner reports. Over: how long it took.
     */
    protected function timingFor(Conversion $conversion): string
    {
        if ($conversion->status->finished()) {
            return $this->tookFor($conversion);
        }

        return match (true) {
            ! $conversion->status->active() => '',
            $conversion->eta_at === null => __('Estimating…'),
            $conversion->eta_at->isPast() => __('Almost done'),
            default => __('ETA :time', ['time' => $this->duration((int) now()->diffInSeconds($conversion->eta_at))]),
        };
    }

    /**
     * What a finished conversion did to the space the game takes: "3.7 GB →
     * 3.4 GB · saved 312 MB (8%)", or "grew" for one that comes out bigger,
     * as a CHD extracted back to ISO does. Nothing until it is done.
     */
    protected function sizesFor(Conversion $conversion): string
    {
        if ($conversion->status !== ConversionStatus::Done || ! $conversion->source_bytes || ! $conversion->output_bytes) {
            return '';
        }

        $difference = $conversion->source_bytes - $conversion->output_bytes;
        $sizes = Number::fileSize($conversion->source_bytes, 1).' → '.Number::fileSize($conversion->output_bytes, 1);
        $percent = (int) round(100 * abs($difference) / $conversion->source_bytes);

        return $difference >= 0
            ? __(':sizes · saved :bytes (:percent%)', ['sizes' => $sizes, 'bytes' => Number::fileSize($difference, 1), 'percent' => $percent])
            : __(':sizes · grew :bytes (:percent%)', ['sizes' => $sizes, 'bytes' => Number::fileSize(-$difference, 1), 'percent' => $percent]);
    }

    /** How long a finished conversion ran, or nothing for one that never started. */
    private function tookFor(Conversion $conversion): string
    {
        if ($conversion->started_at === null || $conversion->finished_at === null) {
            return '';
        }

        $time = $this->duration((int) $conversion->started_at->diffInSeconds($conversion->finished_at));

        return $conversion->status === ConversionStatus::Done
            ? __('Took :time', ['time' => $time])
            : __('Stopped after :time', ['time' => $time]);
    }

    /**
     * A span as the queue writes it: "1min 08sec", "45sec", "1h 05min". The
     * smaller unit is padded so a countdown does not jitter in width.
     */
    private function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return match (true) {
            $hours > 0 => __(':hoursh :minutesmin', ['hours' => $hours, 'minutes' => sprintf('%02d', $minutes)]),
            $minutes > 0 => __(':minutesmin :secondssec', ['minutes' => $minutes, 'seconds' => sprintf('%02d', $seconds % 60)]),
            default => __(':secondssec', ['seconds' => $seconds]),
        };
    }

    /** Called by the live signal, and by a picker that has just queued something. */
    #[On('conversion-queued')]
    public function refreshQueue(): void
    {
        unset($this->queue, $this->logs);
    }
}; ?>

<div
    x-data="{
        stop: null,
        init() { this.stop = live.system('conversion', () => $wire.refreshQueue()) },
        destroy() { this.stop?.() },
    }"
>
    <section class="rounded-xl border border-line bg-surface">
        <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-2">
            <p class="kicker text-fg-faint">{{ __('Queue') }}</p>

            <div class="flex items-center gap-3">
                <p class="font-mono text-xs text-fg-faint">
                    {{ trans_choice(':count conversion runs at a time|:count conversions run at a time', (int) config('converters.concurrency'), ['count' => (int) config('converters.concurrency')]) }}
                </p>

                <flux:button size="xs" variant="ghost" icon="archive-box-x-mark" wire:click="clearFinished">
                    {{ __('Clear finished') }}
                </flux:button>
            </div>
        </div>

        @if ($this->queue->isEmpty())
            <p class="px-4 py-8 text-center text-sm text-fg-faint">{{ __('Nothing queued.') }}</p>
        @else
            <ul class="divide-y divide-line">
                @foreach ($this->queue as $conversion)
                    @php($converter = Converters::make($conversion->converter))
                    @php($sizes = $this->sizesFor($conversion))
                    @php($logOpen = in_array($conversion->id, $openLogs, true))

                    <li wire:key="conversion-{{ $conversion->id }}" class="px-4 py-3">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                            {{-- Each line's box leaves the same 2px over and under
                                 its text — 14px in 18, 12px in 16 — so with one gap
                                 between them the three read evenly spaced. --}}
                            <div class="flex min-w-0 flex-1 flex-col gap-1">
                                <p class="truncate text-sm leading-4.5 text-fg-soft" title="{{ $conversion->label }}">{{ $conversion->label }}</p>
                                <p class="truncate font-mono text-xs leading-4 text-fg-faint">
                                    {{ Console::tryFrom($conversion->console)?->name ?? $conversion->console }} · → .{{ $converter?->to() ?? '?' }} · {{ $converter?->description() ?? $conversion->converter }}
                                </p>
                                @if ($sizes !== '')
                                    <p class="truncate font-mono text-xs leading-4 text-fg-soft">{{ $sizes }}</p>
                                @endif
                            </div>

                            <span @class([
                                'shrink-0 rounded-md border px-1.5 py-0.5 font-mono text-[10px] tracking-kicker uppercase',
                                'border-accent-tint/55 text-accent' => $conversion->status->active(),
                                'border-line-strong text-fg-muted' => in_array($conversion->status, [ConversionStatus::Queued, ConversionStatus::Cancelled], true),
                                'border-success/55 bg-success/15 text-success' => $conversion->status === ConversionStatus::Done,
                                'border-danger/60 text-danger' => $conversion->status === ConversionStatus::Failed,
                            ])>
                                {{ $conversion->status->label() }}
                            </span>


                            {{-- Once done there is nothing left to measure, so the
                                 bar goes and the time it took stands alone. --}}
                            <div @class(['flex shrink-0 flex-col gap-0.5', 'w-40' => $conversion->status !== ConversionStatus::Done])>
                                <span class="font-mono text-[10px] leading-none tracking-kicker text-fg-faint uppercase">{{ $this->timingFor($conversion) }}</span>

                                <div @class(['flex items-center gap-2', 'hidden' => $conversion->status === ConversionStatus::Done])>
                                    <div class="h-1 flex-1 overflow-hidden rounded-sm bg-raised">
                                        <div
                                            @class(['h-full rounded-sm transition-[width] duration-300', 'bg-accent-deep' => $conversion->status !== ConversionStatus::Failed, 'bg-danger' => $conversion->status === ConversionStatus::Failed])
                                            style="width: {{ (int) $conversion->progress }}%"
                                        ></div>
                                    </div>
                                    <span class="w-9 text-right font-mono text-xs text-fg-faint">{{ (int) $conversion->progress }}%</span>
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-1">
                                @if ($conversion->has_log)
                                    <flux:button size="xs" variant="ghost" icon="command-line" wire:click="toggleLog({{ $conversion->id }})" :aria-label="$logOpen ? __('Hide the log') : __('Show the log')" />
                                @endif

                                @if ($conversion->status->cancellable())
                                    <flux:button size="xs" variant="ghost" icon="stop" wire:click="cancel({{ $conversion->id }})" :disabled="$conversion->cancel_requested_at !== null">
                                        {{ $conversion->cancel_requested_at !== null ? __('Stopping') : __('Cancel') }}
                                    </flux:button>
                                @endif

                                @if ($conversion->status->retryable())
                                    <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="retry({{ $conversion->id }})">{{ __('Retry') }}</flux:button>
                                @endif

                                @if ($conversion->status->finished())
                                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="remove({{ $conversion->id }})" :aria-label="__('Remove from the list')" />
                                @endif
                            </div>
                        </div>

                        @if ($conversion->failure !== null)
                            <p class="mt-1.5 text-xs text-danger">{{ $conversion->failure->label() }}</p>
                        @endif

                        @if ($logOpen)
                            {{-- Opened at its foot, and kept there as the running
                                 line is rewritten, unless somebody has scrolled up
                                 to read. --}}
                            <pre
                                x-init="
                                    $el.scrollTop = $el.scrollHeight
                                    const follow = () => { if ($el.scrollHeight - $el.scrollTop - $el.clientHeight < 40) $el.scrollTop = $el.scrollHeight }
                                    new MutationObserver(follow).observe($el, { childList: true, characterData: true, subtree: true })
                                "
                                class="mt-2 max-h-64 overflow-auto rounded-lg border border-line bg-sunken p-3 font-mono text-xs whitespace-pre-wrap text-fg-muted"
                            >{{ Arr::get($this->logs, $conversion->id, '') }}</pre>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
