<?php

use App\Exceptions\ScreenScraper\ScreenScraperException;
use App\Jobs\HashFile;
use App\Jobs\ScrapeGameMedia;
use App\Models\AppSetting;
use App\Models\Game;
use App\Models\GameFile;
use App\Services\GameMatcher;
use App\Services\ScreenScraperService;
use App\Support\Matching\MatchOutcome;
use Flux\Flux;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    public const MODAL = 'identify-game';

    /** As long as HashFile itself is allowed to run, so a slow disc is never given up on early. */
    private const HASH_WAIT_SECONDS = 3600;

    /** The fields a row shows. Media URLs carry the provider credentials and must never reach the browser. */
    private const CANDIDATE_FIELDS = ['provider_id', 'title', 'year', 'region', 'rom_name'];

    private const MD5_MATCH_FIELDS = ['provider_id', 'title', 'release_date', 'genre', 'region', 'players', 'developer', 'publisher'];

    #[Locked]
    public int $gameId;

    public string $search = '';

    /**
     * Locked, because assign() trusts only the ids shown here.
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $md5Match = null;

    /** @var array<int, array<string, mixed>> */
    #[Locked]
    public array $candidates = [];

    #[Locked]
    public bool $searched = false;

    /** The file queued for hashing, kept across a close so reopening does not read it twice. */
    #[Locked]
    public ?int $hashingFileId = null;

    #[Locked]
    public ?int $hashingSince = null;

    /** Whether the modal is showing, so the hash poll stops while it is closed. */
    #[Locked]
    public bool $watching = false;

    /** Looked up by id so the locked prop is the only thing the client holds. */
    #[Computed]
    public function game(): Game
    {
        return Game::findOrFail($this->gameId);
    }

    /** The file whose checksum and name the provider is asked about. */
    #[Computed]
    public function file(): ?GameFile
    {
        return $this->game->identifiableFile();
    }

    /** Opened from the page's actions menu: ask by checksum and by the game's own title. */
    public function open(): void
    {
        $this->reset('md5Match', 'candidates', 'searched');
        $this->search = (string) $this->game->title;
        $this->watching = true;

        $this->askByChecksum();
        $this->lookup();
    }

    /** A name search, the automatic one or one typed in. The checksum answer stands either way. */
    public function lookup(): void
    {
        $console = $this->game->console();
        $name = trim($this->search);

        if ($console === null || $name === '') {
            return;
        }

        try {
            $this->candidates = Collection::make(app(ScreenScraperService::class)->search($console, $name))
                ->map(function (array $candidate): array {
                    return Arr::only($candidate, self::CANDIDATE_FIELDS);
                })
                ->all();
        } catch (ScreenScraperException $e) {
            $this->fail($e);

            return;
        }

        $this->searched = true;
    }

    /** Called by the poll while a hash is outstanding. */
    public function checkHash(): void
    {
        $file = $this->hashingFileId !== null ? GameFile::find($this->hashingFileId) : null;

        if ($file?->md5 !== null) {
            $this->stopHashing();
            $this->askByChecksum();

            return;
        }

        if ($file === null || now()->timestamp - (int) $this->hashingSince >= self::HASH_WAIT_SECONDS) {
            $this->stopHashing();
        }
    }

    /** Wired to the modal's close, so nothing polls behind a hidden dialog. */
    public function closed(): void
    {
        $this->watching = false;
    }

    /** Apply the picked game, then hand back to the page, or to the game it merged into. */
    public function assign(int $providerId, GameMatcher $matcher): void
    {
        if (! $this->offered($providerId)) {
            return;
        }

        try {
            $result = $matcher->assign($this->game, $providerId);
        } catch (ScreenScraperException $e) {
            $this->fail($e);

            return;
        }

        if (! in_array($result->outcome, [MatchOutcome::Matched, MatchOutcome::Merged], true) || $result->game === null) {
            Flux::toast(variant: 'warning', text: __('ScreenScraper returned nothing for that game.'));

            return;
        }

        if (AppSetting::enabled(AppSetting::AUTO_QUEUE_MEDIA_SCRAPE)) {
            // The answer already held every URL, so artwork costs no second lookup.
            ScrapeGameMedia::dispatch($result->game->id, $result->medias);
        }

        Flux::toast(variant: 'success', text: __('Identified as :title.', ['title' => $result->game->title]));

        // This game's row is gone: its files now belong to the one already in the library.
        if ($result->outcome === MatchOutcome::Merged) {
            $this->redirectRoute('games.show', ['game' => $result->game], navigate: true);

            return;
        }

        $this->watching = false;
        Flux::modal(self::MODAL)->close();
        $this->dispatch('game-identified');
    }

    /**
     * The details line under a title: date, genre, region, players.
     *
     * @param  array<string, mixed>  $found
     */
    public function detailLine(array $found): string
    {
        $players = (string) Arr::get($found, 'players');

        return Collection::make([
            Arr::get($found, 'release_date') ?? Arr::get($found, 'year'),
            Arr::get($found, 'genre'),
            Str::upper((string) Arr::get($found, 'region')),
            $players !== '' ? $players.'P' : null,
        ])->filter()->implode(' · ');
    }

    /**
     * "Developer / Publisher", or one name when they are the same studio.
     *
     * @param  array<string, mixed>  $found
     */
    public function studioLine(array $found): string
    {
        return Collection::make([Arr::get($found, 'developer'), Arr::get($found, 'publisher')])
            ->filter()
            ->unique()
            ->implode(' / ');
    }

    /** An exact answer when the file is hashed; otherwise queue the hash and wait for it. */
    private function askByChecksum(): void
    {
        $console = $this->game->console();
        $file = $this->file;

        if ($console === null || $file === null) {
            return;
        }

        if ($file->md5 === null) {
            $this->queueHash($file);

            return;
        }

        try {
            $found = app(ScreenScraperService::class)->lookupByMd5($console, $file->md5);
        } catch (ScreenScraperException $e) {
            $this->fail($e);

            return;
        }

        $this->md5Match = $found !== null ? Arr::only($found, self::MD5_MATCH_FIELDS) : null;
    }

    /** Once per file: a second HashFile would read the same image twice at once. */
    private function queueHash(GameFile $file): void
    {
        if ($this->hashingFileId === $file->id) {
            return;
        }

        HashFile::dispatch($file->id);

        $this->hashingFileId = $file->id;
        $this->hashingSince = now()->timestamp;
    }

    /** Stop the hash poll. */
    private function stopHashing(): void
    {
        $this->hashingFileId = null;
        $this->hashingSince = null;
    }

    /** Only an id this modal listed may be applied. */
    private function offered(int $providerId): bool
    {
        return Collection::make($this->candidates)
            ->push($this->md5Match ?? [])
            ->pluck('provider_id')
            ->contains((string) $providerId);
    }

    /** Log the provider's reason and show a fixed one. */
    private function fail(ScreenScraperException $e): void
    {
        // Redacted by the service; logged rather than shown, as every provider error is.
        Log::warning('Manual identification failed.', ['game' => $this->gameId, 'reason' => $e->getMessage()]);

        Flux::toast(variant: 'danger', text: __('ScreenScraper could not be reached. Try again in a moment.'));
    }

};
?>

<div x-on:identify-game.window="$flux.modal('{{ $this::MODAL }}').show(); $wire.open()">
    <flux:modal :name="$this::MODAL" wire:close="closed" class="w-full max-w-2xl">
        <div class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Identify game') }}</flux:heading>
                @if ($this->file !== null)
                    <p class="mt-0.5 truncate font-mono text-xs text-fg-faint">{{ $this->file->filename }}</p>
                @endif
            </div>

            <form wire:submit="lookup" class="flex gap-2">
                <flux:input wire:model="search" icon="magnifying-glass" :placeholder="__('e.g. Super Mario Sunshine')" class="flex-1" />
                <flux:button type="submit">{{ __('Search') }}</flux:button>
            </form>

            <div wire:loading.flex wire:target="open, lookup" class="items-center justify-center gap-2 py-6 text-sm text-fg-soft">
                <flux:icon.loading class="size-4" />
                {{ __('Searching ScreenScraper…') }}
            </div>

            <div wire:loading.remove wire:target="open, lookup" class="flex flex-col gap-5">
                @if ($hashingFileId !== null && $watching)
                    <div wire:poll.3s="checkHash" class="flex items-center gap-2 rounded-lg border border-line-input bg-sunken px-3 py-2 text-sm text-fg-soft">
                        <flux:icon.loading class="size-3.5 text-fg-muted" />
                        {{ __('Hashing file for an exact match…') }}
                    </div>
                @endif

                @if ($md5Match !== null)
                    <section>
                        <div class="mb-2 flex items-center gap-2">
                            <span class="size-1.5 rounded-full bg-accent shadow-glow" aria-hidden="true"></span>
                            <h3 class="kicker text-accent">{{ __('Exact MD5 match') }}</h3>
                        </div>
                        <button type="button" wire:click="assign({{ (int) Arr::get($md5Match, 'provider_id') }})" wire:loading.attr="disabled" wire:target="assign"
                                class="flex w-full cursor-pointer items-center gap-3 rounded-lg border border-accent-tint/40 bg-accent-tint/6 px-3 py-2.5 text-left transition-colors hover:bg-accent-tint/10 disabled:cursor-wait">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm text-fg-bright">{{ Arr::get($md5Match, 'title') ?? __('(no title)') }}</span>
                                <span class="mt-0.5 block font-mono text-xs text-fg-dim">{{ $this->detailLine($md5Match) }}</span>
                                <span class="mt-0.5 block truncate text-sm text-fg-faint">{{ $this->studioLine($md5Match) }}</span>
                            </span>
                        </button>
                    </section>
                @endif

                @if ($candidates !== [])
                    <section>
                        <h3 class="mb-2 kicker text-fg-faint">
                            {{ __('Name matches') }} <span class="text-fg-dim">({{ count($candidates) }})</span>
                        </h3>
                        <div class="-mx-2 max-h-96 overflow-y-auto px-2">
                            @foreach ($candidates as $candidate)
                                @php(['provider_id' => $providerId, 'title' => $title, 'rom_name' => $romName] = $candidate)
                                <button type="button" wire:key="candidate-{{ $providerId }}"
                                        wire:click="assign({{ (int) $providerId }})" wire:loading.attr="disabled" wire:target="assign"
                                        class="flex w-full cursor-pointer items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors hover:bg-hover disabled:cursor-wait">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm text-fg-bright">{{ $title ?? __('(no title)') }}</span>
                                        <span class="mt-0.5 block font-mono text-xs text-fg-dim">{{ $this->detailLine($candidate) }}</span>
                                        @if ($romName)
                                            <span class="mt-0.5 block truncate font-mono text-xs text-fg-faint">{{ $romName }}</span>
                                        @endif
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    </section>
                @elseif ($searched && $md5Match === null)
                    <p class="rounded-lg border border-dashed border-line-input px-4 py-6 text-center text-sm text-fg-faint">
                        {{ __('No matches found. Try searching with a different title.') }}
                    </p>
                @endif
            </div>

            <div class="flex items-center justify-between gap-2">
                <span class="font-mono text-xs text-fg-faint">{{ __('Powered by ScreenScraper.fr') }}</span>
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Close') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
