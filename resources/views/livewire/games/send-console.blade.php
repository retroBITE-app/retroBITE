<?php

use App\Enums\GameStatus;
use App\Models\Destination;
use App\Models\Game;
use App\Models\Transfer;
use App\Support\Console;
use App\Transfers\SendToShare;
use App\Transfers\TransferRejected;
use App\Transfers\TransferTargets;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * Send every identified game of a console somewhere, one copy of each: from
 * the shelf's menu, to a USB drive or a saved network share. The modal is the
 * game page's own (x-transfer-modal); this holds what the shelf adds to it —
 * the share's batch, and the banner that follows it. See App\Transfers\
 * ConsoleTransfer for which games go.
 */
new class extends Component
{
    public const EVENT = 'send-console';

    #[Locked]
    public string $console;

    /**
     * Rendered only once asked for: the modal loads the console's plan as it
     * appears, and every visit to the shelf is not a reason to build one.
     */
    public bool $open = false;

    /** The batch this page is following, if any. */
    public ?string $watching = null;

    public function mount(): void
    {
        $running = $this->progress;
        $this->watching = $running !== null && ! $running['finished'] ? $running['batch'] : null;
    }

    #[Computed]
    public function target(): Console
    {
        $console = Console::tryFrom($this->console);

        abort_if($console === null, 404);

        return $console;
    }

    /** @return EloquentCollection<int, Destination> */
    #[Computed]
    public function destinations(): EloquentCollection
    {
        return Destination::query()->orderBy('name')->get();
    }

    /** How many games would go: identified, with a file on disk. */
    #[Computed]
    public function count(): int
    {
        return Game::query()
            ->forConsole($this->console)
            ->where('status', GameStatus::Matched)
            ->whereHas('files', fn ($query) => $query->present())
            ->count();
    }

    /**
     * The console's latest send to a share, counted by how its games went.
     *
     * @return array{batch: string, destination: string, total: int, copied: int, present: int, done: int, failed: int, finished: bool}|null
     */
    #[Computed]
    public function progress(): ?array
    {
        $latest = Transfer::query()
            ->with('destination')
            ->whereNotNull('batch_id')
            ->whereHas('game', fn ($query) => $query->forConsole($this->console))
            ->latest('id')
            ->first();

        if ($latest === null || $latest->batch_id === null) {
            return null;
        }

        // Copied counts as it happens; a game is only done once the list is
        // written, after the last of them, so done alone would sit at nought.
        $row = Transfer::query()
            ->where('batch_id', $latest->batch_id)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(status = ?) as done', [Transfer::DONE])
            ->selectRaw('sum(status = ?) as failed', [Transfer::FAILED])
            ->selectRaw('sum(status <> ? and files_done >= files_total) as copied', [Transfer::FAILED])
            ->selectRaw('sum(status <> ? and files_total > 0 and files_skipped >= files_total) as present', [Transfer::FAILED])
            ->first();

        $total = (int) $row?->getAttribute('total');
        $done = (int) $row?->getAttribute('done');
        $failed = (int) $row?->getAttribute('failed');

        return [
            'batch' => $latest->batch_id,
            'destination' => $latest->destination->name ?? __('the share'),
            'total' => $total,
            'copied' => (int) $row?->getAttribute('copied'),
            'present' => (int) $row?->getAttribute('present'),
            'done' => $done,
            'failed' => $failed,
            'finished' => $done + $failed === $total,
        ];
    }

    public function openModal(): void
    {
        $this->open = true;

        $this->js("\$flux.modal('transfer').show()");
    }

    /**
     * Start from the modal, for a share: the server copies, and the banner follows it.
     *
     * @param  array<string, mixed>  $options  the region and artwork chosen in the modal (TransferOptions)
     */
    public function sendToShare(int $destinationId, string $target, SendToShare $sender, array $options = []): void
    {
        $destination = Destination::query()->find($destinationId);
        $transferTarget = TransferTargets::chosen($target, $options);

        if ($destination === null || $transferTarget === null) {
            Flux::toast(variant: 'warning', text: __('That destination is no longer there.'));

            return;
        }

        try {
            $sent = $sender->sendConsole($this->console, $transferTarget, $destination);
        } catch (TransferRejected $e) {
            Flux::toast(variant: 'warning', text: $e->getMessage());

            return;
        }

        $this->watching = $sent['batch'];
        unset($this->progress);

        $this->js("\$flux.modal('transfer').close()");
        Flux::toast(variant: 'success', text: trans_choice(
            '{1} Sending one game to :name.|[2,*] Sending :count games to :name.',
            $sent['games'],
            ['count' => $sent['games'], 'name' => $destination->name],
        ));
    }

    /** Run on each transfer signal: re-read the counts, and say how it ended. */
    public function checkProgress(): void
    {
        unset($this->progress);
        $progress = $this->progress;

        if ($progress === null || $progress['batch'] !== $this->watching || ! $progress['finished']) {
            return;
        }

        $this->watching = null;

        // A send run again finds most of its games there already; said, so a
        // quick finish does not read as nothing having happened.
        $there = $progress['present'] > 0
            ? ' '.trans_choice('{1} One was already there.|[2,*] :count were already there.', $progress['present'], ['count' => $progress['present']])
            : '';

        if ($progress['failed'] === 0) {
            Flux::toast(variant: 'success', text: __('Sent :count games to :name.', ['count' => $progress['done'], 'name' => $progress['destination']]).$there);
        } else {
            Flux::toast(variant: 'warning', text: __('Sent :done of :total games to :name. :failed could not be sent; their pages say why.', [
                'done' => $progress['done'],
                'total' => $progress['total'],
                'failed' => $progress['failed'],
                'name' => $progress['destination'],
            ]));
        }
    }
}; ?>

<div x-on:{{ $this::EVENT }}.window="$wire.openModal()">
    @if ($watching !== null && ($sending = $this->progress) !== null)
        {{-- A console on its way to a share. Signalled per game copied, so
             no poll; a reconnect re-reads in case one was missed. --}}
        <div x-data="{ stop: null, init() { this.stop = live.system('transfer', () => $wire.checkProgress()) }, destroy() { this.stop?.() } }"
             class="mx-4 mt-4 flex flex-wrap items-center gap-3 rounded-xl border border-accent-tint/55 bg-accent-tint/10 px-5 py-3 lg:mx-8">
            <flux:icon.arrow-path class="size-4 animate-spin text-accent" />
            <p class="text-sm text-accent">{{ __('Sending :console to :name…', ['console' => $this->target->name, 'name' => $sending['destination']]) }}</p>
            <p class="ml-auto font-mono text-xs text-accent">
                {{ $sending['copied'] + $sending['failed'] }} / {{ $sending['total'] }}
                @if ($sending['present'] > 0)
                    · {{ __(':count already there', ['count' => $sending['present']]) }}
                @endif
                @if ($sending['failed'] > 0)
                    · {{ trans_choice(':count failed|:count failed', $sending['failed'], ['count' => $sending['failed']]) }}
                @endif
            </p>
        </div>
    @endif

    @if ($open)
        <x-transfer-modal :console="$this->target" :count="$this->count" :destinations="$this->destinations" />
    @endif
</div>
