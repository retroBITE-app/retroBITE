<?php

use App\Conversion\ConversionQueue;
use App\Conversion\Converter;
use App\Conversion\Converters;
use App\Conversion\Setting;
use App\Conversion\SourceSet;
use App\Conversion\Tools;
use App\Exceptions\ConversionFailed;
use App\Models\ConsoleSourceFolder;
use App\Models\ConversionStat;
use App\Models\Game;
use App\Models\GameFile;
use App\Support\Console;
use Flux\Flux;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * Pick what to convert and what it should become, and queue it.
 *
 * The source list and the output panel of a conversion, in one place for both
 * pages that offer one: Tools → Conversion, over a whole console, and a game's
 * own Conversion tab, over that game's files. Everything offered comes from
 * Converters::candidatesFor(), the one gate that knows which console reads
 * which format, so it never lists a conversion the job would refuse — and the
 * job asks again anyway. Queueing tells the queue beside it to look again.
 */
new class extends Component
{
    /** How many games the list draws; the search narrows past that. */
    public const LIST_LIMIT = 200;

    #[Locked]
    public string $consoleKey = '';

    /** Set on a game's page: the list is that game's files and nothing else. */
    #[Locked]
    public ?int $gameId = null;

    /** Narrows the list to the games whose title, or a file's name, has it. */
    public string $search = '';

    /** A source format, as formatOf() names it, to list alone; '' for every one. */
    public string $type = '';

    /**
     * The picked sources, by the row each was picked by. One or many: every
     * one of them that can become the chosen format is queued as it.
     *
     * Locked: only toggleSource() and toggleAll() change it, and they check
     * what they add.
     *
     * @var list<int>
     */
    #[Locked]
    public array $sources = [];

    /** Locked: only selectFormat() and the picks change it. */
    #[Locked]
    public string $format = '';

    public bool $verify = true;

    public string $compression = '';

    public bool $keepSource = true;

    /**
     * The chosen format's advanced settings, key => value, reset to its
     * defaults whenever the format changes.
     *
     * @var array<string, string>
     */
    public array $advanced = [];

    /** A game with one thing to convert has it picked, so its formats show at once. */
    public function mount(): void
    {
        if ($this->gameId !== null && $this->sets->count() === 1) {
            $this->sources = [$this->sets->first()->file->id];
        }

        $this->selectionChanged();
    }

    #[Computed]
    public function console(): ?Console
    {
        $console = Console::tryFrom($this->consoleKey);

        return $console !== null && Converters::offersOn($console) ? $console : null;
    }

    /**
     * Every set some converter reads — the console's, or on a game's page the
     * game's — by the row that picks it. Read once per request: the list,
     * the picks and every check against them come out of this. Not all():
     * Livewire's own all() is the component's properties, which debugbar
     * and Livewire itself read.
     *
     * @return Collection<int, SourceSet>
     */
    #[Computed]
    public function available(): Collection
    {
        if ($this->console === null) {
            return collect();
        }

        $game = $this->gameId !== null ? Game::query()->find($this->gameId) : null;

        if ($this->gameId !== null && $game === null) {
            return collect();
        }

        return ($game !== null ? SourceSet::forGame($game) : SourceSet::forConsole($this->console))
            ->filter(function (SourceSet $set): bool {
                return Converters::reads($set);
            })
            ->keyBy(function (SourceSet $set): int {
                return $set->file->id;
            });
    }

    /**
     * What the list shows: everything, narrowed by the search and the type.
     *
     * @return Collection<int, SourceSet>
     */
    #[Computed]
    public function sets(): Collection
    {
        $search = Str::lower(trim($this->search));

        return $this->available
            ->filter(function (SourceSet $set) use ($search): bool {
                if ($this->type !== '' && $this->formatOf($set) !== $this->type) {
                    return false;
                }

                return $search === '' || Str::contains(Str::lower($this->titleOf($set).' '.$set->label().' '.$set->directory), $search);
            })
            ->values();
    }

    /**
     * The shown sets a game at a time, games in title order: one game kept
     * as an ISO and a ZSO is one entry with both under it.
     *
     * @return Collection<int, array{key: string, title: string, sets: Collection<int, SourceSet>}>
     */
    #[Computed]
    public function games(): Collection
    {
        return $this->sets
            ->groupBy(function (SourceSet $set): string {
                return $set->game !== null ? 'game-'.$set->game->id : 'file-'.$set->file->id;
            })
            ->map(function (Collection $sets, string $key): array {
                return ['key' => $key, 'title' => $this->titleOf($sets->first()), 'sets' => $sets->values()];
            })
            ->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * The sets of the games the list draws, which select-all picks from.
     *
     * @return Collection<int, SourceSet>
     */
    #[Computed]
    public function shown(): Collection
    {
        return $this->games
            ->take(self::LIST_LIMIT)
            ->flatMap(function (array $game): Collection {
                return Arr::get($game, 'sets');
            })
            ->values();
    }

    /**
     * Every source format on the list before the type narrows it, for the
     * type filter's options.
     *
     * @return list<string>
     */
    #[Computed]
    public function types(): array
    {
        return array_values($this->available
            ->map(function (SourceSet $set): string {
                return $this->formatOf($set);
            })
            ->unique()
            ->sort()
            ->all());
    }

    /**
     * The picked sets, each with the conversions it could become. Picks no
     * longer on the list — gone from disk, or another game's on a game's
     * page — drop out.
     *
     * @return Collection<int, array{set: SourceSet, keys: list<string>}>
     */
    #[Computed]
    public function selected(): Collection
    {
        return $this->available
            ->only($this->sources)
            ->map(function (SourceSet $set): array {
                return [
                    'set' => $set,
                    'keys' => Converters::candidatesFor($set)
                        ->map(function (Converter $converter): string {
                            return $converter->key();
                        })
                        ->all(),
                ];
            })
            ->values();
    }

    /**
     * What the picked sets can become, in the console's order: every format
     * any of them can, with how many it fits, whether its tools are here,
     * and how big the lot should come out — going by what that conversion
     * has made of this console's discs before, and not at all until it has
     * made any.
     *
     * @return list<array{converter: Converter, available: bool, missing: string|null, hint: string|null, fits: int}>
     */
    #[Computed]
    public function formats(): array
    {
        $selected = $this->selected;

        if ($selected->isEmpty() || $this->console === null) {
            return [];
        }

        $ratios = ConversionStat::ratios($this->consoleKey);

        return collect($this->console->converters)
            ->map(function (string $key) use ($selected, $ratios): ?array {
                $fitting = $selected->filter(function (array $entry) use ($key): bool {
                    return in_array($key, $entry['keys'], true);
                });
                $converter = Converters::make($key);

                if ($fitting->isEmpty() || $converter === null) {
                    return null;
                }

                $ratio = Arr::get($ratios, $key);
                $bytes = (int) $fitting->sum(function (array $entry): int {
                    return $entry['set']->bytes();
                });
                $hint = $ratio !== null ? (int) round($bytes * $ratio) : 0;
                $missing = Tools::missing($converter);

                return [
                    'converter' => $converter,
                    'available' => $missing === null,
                    'missing' => $missing,
                    'hint' => $hint > 0 ? Number::fileSize($hint, 1) : null,
                    'fits' => $fitting->count(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    #[Computed]
    public function chosen(): ?Converter
    {
        foreach ($this->formats as ['converter' => $converter, 'available' => $available]) {
            if ($available && $converter->key() === $this->format) {
                return $converter;
            }
        }

        return null;
    }

    /**
     * Pick a source, or put it back. Picks share one source format — ISO
     * with ISO, RVZ with RVZ — so one set of options means the same thing
     * for all of them; a source of another format is refused.
     */
    public function toggleSource(int $id): void
    {
        if (in_array($id, $this->sources, true)) {
            $this->sources = array_values(array_diff($this->sources, [$id]));
            $this->selectionChanged();

            return;
        }

        // Looked up in everything available, not only what the search shows: the search
        // can change in the same request as the click.
        $set = $this->available->get($id);
        $picked = $this->pickedFormat;

        if ($set === null) {
            return;
        }

        if ($picked !== null && $this->formatOf($set) !== $picked) {
            Flux::toast(variant: 'warning', text: __('Only :format files can be picked with the ones already picked. Clear them to pick another format.', ['format' => Str::upper($picked)]));

            return;
        }

        $this->sources = [...$this->sources, $id];
        $this->selectionChanged();
    }

    /**
     * Pick everything the list shows in the picked format — or, with nothing
     * picked yet, in the format of the first row — and with all of those
     * picked already, put them back.
     */
    public function toggleAll(): void
    {
        $shown = $this->shown;
        $format = $this->pickedFormat ?? ($shown->isNotEmpty() ? $this->formatOf($shown->first()) : null);

        if ($format === null) {
            return;
        }

        $eligible = $shown
            ->filter(function (SourceSet $set) use ($format): bool {
                return $this->formatOf($set) === $format;
            })
            ->map(function (SourceSet $set): int {
                return $set->file->id;
            })
            ->values()
            ->all();

        if (array_diff($eligible, $this->sources) === []) {
            $this->sources = array_values(array_diff($this->sources, $eligible));
            $this->selectionChanged();

            return;
        }

        $this->sources = array_values(array_unique([...$this->sources, ...$eligible]));
        $this->selectionChanged();

        if (count($eligible) < $shown->count()) {
            Flux::toast(text: __('Picked the :format files. Files of other formats are left out: one format is converted at a time.', ['format' => Str::upper($format)]));
        }
    }

    /** The source format every pick shares, or null with nothing picked. */
    #[Computed]
    public function pickedFormat(): ?string
    {
        $set = $this->single ?? Arr::get($this->selected->first() ?? [], 'set');

        return $set instanceof SourceSet ? $this->formatOf($set) : null;
    }

    /** The one pick, when there is exactly one: the panel names it. */
    #[Computed]
    public function single(): ?SourceSet
    {
        return $this->selected->count() === 1 ? Arr::get($this->selected->first(), 'set') : null;
    }

    /**
     * Where the output goes, for the panel: the pick's own folder, or beside
     * each pick when there are several.
     */
    #[Computed]
    public function writesTo(): string
    {
        $root = (string) ConsoleSourceFolder::pathFor($this->console ?? new Console($this->consoleKey));

        return $this->single !== null
            ? trim($root.'/'.$this->single->directory, '/').'/'
            : __('Beside each source, in :folder/', ['folder' => $root]);
    }

    /** A source's format, as the picks are matched on: its discs' extensions. */
    protected function formatOf(SourceSet $set): string
    {
        return implode('/', $set->extensions());
    }

    /** The game a source belongs to, by title; the source's own name when the game has none. */
    protected function titleOf(SourceSet $set): string
    {
        $title = trim((string) $set->game?->title);

        return $title !== '' ? $title : $set->label();
    }

    public function clearSelection(): void
    {
        $this->sources = [];
        $this->selectionChanged();
    }

    public function selectFormat(string $key): void
    {
        $this->format = $key;
        unset($this->chosen);

        $this->choose($this->chosen);
    }

    /**
     * Queue every picked source that can become the chosen format, one
     * conversion each with the same options; say how many could not.
     */
    public function add(ConversionQueue $queue): void
    {
        $chosen = $this->chosen;

        if ($this->selected->isEmpty() || $chosen === null) {
            Flux::toast(variant: 'warning', text: __('Pick a source and a format first.'));

            return;
        }

        $options = [
            Converter::VERIFY => $this->verify,
            Converter::COMPRESSION => $this->compression,
            Converter::KEEP_SOURCE => $this->keepSource,
            Converter::ADVANCED => $this->advanced,
        ];

        // The ones the format does not fit are left out here; the rest go
        // through the gate again in addMany(), which counts any it refuses.
        [$fitting, $unfit] = $this->selected->partition(function (array $entry) use ($chosen): bool {
            return in_array($chosen->key(), Arr::get($entry, 'keys', []), true);
        });

        ['queued' => $queued, 'skipped' => $refused] = $queue->addMany($fitting->pluck('set'), $chosen->key(), $options);
        $skipped = $unfit->count() + $refused;

        if ($queued === []) {
            Flux::toast(variant: 'danger', text: __('Nothing picked can be converted to :format.', ['format' => $chosen->label()]));

            return;
        }

        $this->clearSelection();
        $this->dispatch('conversion-queued');

        Flux::toast(
            variant: $skipped > 0 ? 'warning' : 'success',
            text: match (true) {
                $skipped > 0 => trans_choice(':count is queued as :format; :skipped could not become it.|:count are queued as :format; :skipped could not become it.', count($queued), [
                    'count' => count($queued), 'format' => $chosen->label(), 'skipped' => $skipped,
                ]),
                count($queued) === 1 => __(':name is queued as :format.', ['name' => $queued[0]->label, 'format' => $chosen->label()]),
                default => __(':count are queued as :format.', ['count' => count($queued), 'format' => $chosen->label()]),
            },
        );
    }

    /**
     * After the picks change: keep the format while some pick can still
     * become it, and otherwise take the first one offered, so a single click
     * on a game is enough to queue it.
     */
    private function selectionChanged(): void
    {
        unset($this->selected, $this->single, $this->pickedFormat, $this->pickState, $this->selectedBytes, $this->formats, $this->chosen, $this->fitsChosen, $this->writesTo);

        if ($this->chosen !== null) {
            return;
        }

        $first = collect($this->formats)->first(function (array $format): bool {
            return $format['available'];
        });

        $this->choose($first !== null ? $first['converter'] : null);
    }

    /**
     * How much of what select-all would pick is picked, for its box: 'off',
     * 'some' or 'on'. Only the rows in the picked format count.
     */
    #[Computed]
    public function pickState(): string
    {
        $format = $this->pickedFormat;
        $shown = $this->shown
            ->filter(function (SourceSet $set) use ($format): bool {
                return $format === null || $this->formatOf($set) === $format;
            })
            ->map(function (SourceSet $set): int {
                return $set->file->id;
            });
        $picked = $shown->intersect($this->sources)->count();

        return match (true) {
            $picked === 0 => 'off',
            $picked === $shown->count() => 'on',
            default => 'some',
        };
    }

    /** What the picked sources take up together. */
    #[Computed]
    public function selectedBytes(): int
    {
        return (int) $this->selected->sum(function (array $entry): int {
            return $entry['set']->bytes();
        });
    }

    /** How many of the picks the chosen format fits, for the Add button. */
    #[Computed]
    public function fitsChosen(): int
    {
        $format = collect($this->formats)->first(function (array $format): bool {
            return $format['converter']->key() === $this->format;
        });

        return $format !== null ? (int) Arr::get($format, 'fits', 0) : 0;
    }

    /** Make a format the chosen one, its compression and advanced settings at their defaults. */
    private function choose(?Converter $converter): void
    {
        $this->format = $converter?->key() ?? '';
        $this->compression = $converter?->compression([]) ?? '';
        $this->advanced = collect($converter?->settings() ?? [])
            ->mapWithKeys(function (Setting $setting): array {
                return [$setting->key => $setting->default];
            })
            ->all();

        unset($this->chosen, $this->fitsChosen);
    }
}; ?>

{{--
    The source list beside the output panel. The console tabs, when there are
    any, belong to the page around it.
--}}
<div class="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
    {{-- Sources --}}
    <section class="flex min-w-0 flex-col rounded-xl border border-line bg-surface">
                @php($games = $this->games)
                @php($shown = $this->shown)
                @php($pickState = $this->pickState)
                @php($pickedFormat = $this->pickedFormat)

                {{-- Select all sits at the head of the list's own column of
                     checkboxes, px-3.5 like the rows so the boxes line up,
                     and picks what the search and type beside it show. --}}
                <div class="flex items-center gap-3 border-b border-line px-3.5 py-3">
                    <button
                        type="button"
                        wire:click="toggleAll"
                        @disabled($shown->isEmpty())
                        aria-label="{{ $pickState === 'on' ? __('Clear all') : __('Select all') }}"
                        title="{{ $pickState === 'on' ? __('Clear all') : __('Select all') }}"
                        class="shrink-0 cursor-pointer disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <x-conversion.check :state="$pickState" />
                    </button>

                    @if ($gameId === null)
                        <x-search-field wire:model.live.debounce.300ms="search" :placeholder="__('Search games')" class="min-w-0 flex-1" />

                        <flux:select wire:model.live="type" size="sm" class="w-28 shrink-0" :aria-label="__('File type')">
                            <flux:select.option value="">{{ __('All types') }}</flux:select.option>
                            @foreach ($this->types as $option)
                                <flux:select.option value="{{ $option }}">{{ Str::upper($option) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @else
                        <span class="min-w-0 flex-1 text-xs text-fg-faint">{{ __('Pick what to convert') }}</span>
                    @endif

                    {{-- One group on one baseline: the count is mono and the
                         link is not, and centred apart they sit at two heights. --}}
                    @if ($this->selected->isNotEmpty())
                        <span class="flex shrink-0 items-baseline gap-2 text-xs leading-none">
                            <span class="font-mono text-fg-faint">{{ __(':count picked', ['count' => $this->selected->count()]) }}</span>
                            <button type="button" wire:click="clearSelection" class="cursor-pointer text-fg-faint transition-colors hover:text-fg-soft">{{ __('Clear') }}</button>
                        </span>
                    @endif
                </div>

                <ul class="max-h-[28rem] divide-y divide-line overflow-y-auto">
                    @forelse ($games->take($this::LIST_LIMIT) as ['key' => $key, 'title' => $title, 'sets' => $gameSets])
                        {{-- A game kept in more than one file is a heading with
                             its files under it; a game of one file is one row,
                             named after the game with the file beneath. A game's
                             own page lists only its files, so it names each. --}}
                        @php($grouped = $gameId === null && $gameSets->count() > 1)

                        {{-- Folded until the title is clicked, or open from the
                             start when one of its files is picked. Alpine keeps
                             the fold across re-renders: the li is keyed. --}}
                        @php($holdsPick = $grouped && $gameSets->contains(function (SourceSet $set) use ($sources): bool {
                            return in_array($set->file->id, $sources, true);
                        }))

                        <li wire:key="{{ $key }}" @if ($grouped) x-data="{ open: @js($holdsPick) }" x-bind:class="open && 'pb-1.5'" @endif>
                            @if ($grouped)
                                <button
                                    type="button"
                                    x-on:click="open = ! open"
                                    x-bind:aria-expanded="open"
                                    class="flex w-full cursor-pointer items-center gap-3 px-3.5 py-2.5 text-left transition-colors hover:bg-hover"
                                >
                                    <span class="size-4 shrink-0" aria-hidden="true"></span>
                                    <span class="flex min-w-0 flex-1 items-center gap-1.5">
                                        <span @class(['truncate text-sm', 'text-accent' => $holdsPick, 'text-fg' => ! $holdsPick])>{{ $title }}</span>
                                        <flux:icon.chevron-down class="size-3 shrink-0 text-fg-dim transition-transform duration-200" x-bind:class="open && '-rotate-180'" />
                                    </span>
                                    <span class="shrink-0 font-mono text-[10px] tracking-kicker text-fg-faint uppercase">
                                        {{ trans_choice(':count file|:count files', $gameSets->count(), ['count' => $gameSets->count()]) }}
                                    </span>
                                </button>
                            @endif

                            @foreach ($gameSets as $set)
                                @php($picked = in_array($set->file->id, $sources, true))
                                {{-- Another format than the picks: it cannot join them. --}}
                                @php($blocked = ! $picked && $pickedFormat !== null && $this->formatOf($set) !== $pickedFormat)
                                @php($path = $set->directory !== '' ? $set->directory.'/' : '/')
                                @php($named = $gameId === null && ! $grouped)

                                <button
                                    type="button"
                                    wire:key="set-{{ $set->file->id }}"
                                    wire:click="toggleSource({{ $set->file->id }})"
                                    @if ($grouped) x-show="open" x-cloak @endif
                                    aria-pressed="{{ $picked ? 'true' : 'false' }}"
                                    @disabled($blocked)
                                    @if ($blocked) title="{{ __('Only :format files can be picked with the ones already picked', ['format' => Str::upper($pickedFormat)]) }}" @endif
                                    @class([
                                        'flex w-full items-center gap-3 px-3.5 text-left transition-colors',
                                        'py-1.5' => $grouped,
                                        'py-2.5' => ! $grouped,
                                        'cursor-pointer bg-accent-tint/10' => $picked,
                                        'cursor-pointer hover:bg-hover' => ! $picked && ! $blocked,
                                        'cursor-not-allowed opacity-40' => $blocked,
                                    ])
                                >
                                    <x-conversion.check :state="$picked ? 'on' : 'off'" />

                                    {{-- Named after the game, the file goes on a line
                                         under it; otherwise the file is the one line,
                                         its folder in front of it. --}}
                                    @if ($named)
                                        <span class="min-w-0 flex-1">
                                            <span @class(['block truncate text-sm', 'text-accent' => $picked, 'text-fg' => ! $picked])>{{ $title }}</span>
                                            <span class="block truncate font-mono text-xs text-fg-faint">{{ $path.$set->label() }}</span>
                                        </span>
                                    @else
                                        <span class="flex min-w-0 flex-1 items-baseline">
                                            <span class="max-w-1/2 shrink-0 truncate font-mono text-xs text-fg-faint">{{ $path }}</span>
                                            <span @class(['truncate text-sm', 'text-accent' => $picked, 'text-fg-soft' => ! $picked])>{{ $set->label() }}</span>
                                        </span>
                                    @endif

                                    @if ($set->isSet())
                                        <span class="shrink-0 rounded-md border border-line-strong px-1.5 py-0.5 font-mono text-[10px] tracking-kicker text-fg-muted uppercase">
                                            {{ trans_choice(':count disc|:count discs', count($set->discs), ['count' => count($set->discs)]) }}
                                        </span>
                                    @endif

                                    <span class="shrink-0 font-mono text-xs text-fg-dim uppercase">{{ implode('/', $set->extensions()) }}</span>
                                    <span class="w-16 shrink-0 text-right font-mono text-xs text-fg-faint">{{ Number::fileSize($set->bytes(), 1) }}</span>
                                </button>
                            @endforeach
                        </li>
                    @empty
                        <li class="px-4 py-8 text-center text-sm text-fg-faint">
                            {{ match (true) {
                                $search !== '' || $type !== '' => __('No game matches the search.'),
                                $gameId !== null => __('Nothing here any of this console\'s conversions reads.'),
                                default => __('Nothing on this console can be converted. Scan the folder if files were added.'),
                            } }}
                        </li>
                    @endforelse
                </ul>

                @if ($games->count() > $this::LIST_LIMIT)
                    <p class="border-t border-line px-3.5 py-2 text-xs text-fg-faint">
                        {{ __('Showing :shown of :count games. Search to find the rest.', ['shown' => $this::LIST_LIMIT, 'count' => $games->count()]) }}
                    </p>
                @endif
            </section>

            {{-- Output --}}
            <section class="flex min-w-0 flex-col gap-5 rounded-xl border border-line bg-surface p-4">
                @php($selected = $this->selected)
                @php($set = $this->single)

                @if ($selected->isEmpty())
                    <p class="py-10 text-center text-sm text-fg-faint">{{ __('Pick a file to see what it can become, or several to convert them all at once.') }}</p>
                @else
                    <div>
                        <p class="kicker mb-1 text-fg-faint">{{ $set !== null ? __('Source') : __('Sources') }}</p>
                        @if ($set !== null)
                            <p class="truncate text-sm text-fg-soft" title="{{ $set->label() }}">{{ $set->label() }}</p>
                            @if ($set->isSet())
                                <p class="mt-0.5 text-xs text-fg-faint">
                                    {{ __('A set of :count discs: each is converted, and a playlist of the new discs is written beside them.', ['count' => count($set->discs)]) }}
                                </p>
                            @endif
                        @else
                            <p class="text-sm text-fg-soft">
                                {{ __(':count files · :size', [
                                    'count' => $selected->count(),
                                    'size' => Number::fileSize($this->selectedBytes, 1),
                                ]) }}
                            </p>
                            <p class="mt-0.5 text-xs text-fg-faint">{{ __('Each is queued as a conversion of its own, with the options below.') }}</p>
                        @endif
                    </div>

                    <div>
                        <p class="kicker mb-2 text-fg-faint">{{ __('Output format') }}</p>

                        @if ($this->formats === [])
                            <p class="text-sm text-fg-faint">{{ __('Nothing this console reads can be made from it.') }}</p>
                        @else
                            <div class="grid gap-1.5">
                                @foreach ($this->formats as ['converter' => $converter, 'available' => $available, 'missing' => $missing, 'hint' => $hint, 'fits' => $fits])
                                    @php($on = $available && $format === $converter->key())

                                    <button
                                        type="button"
                                        wire:key="format-{{ $converter->key() }}"
                                        wire:click="selectFormat(@js($converter->key()))"
                                        @disabled(! $available)
                                        @class([
                                            'flex items-center gap-3 rounded-lg border px-3.5 py-2.5 text-left transition-colors',
                                            'cursor-pointer border-accent-tint/55 bg-accent-tint/10' => $on,
                                            'cursor-pointer border-line-input hover:bg-hover' => $available && ! $on,
                                            'cursor-not-allowed border-line opacity-50' => ! $available,
                                        ])
                                    >
                                        <span @class([
                                            'grid size-4 shrink-0 place-items-center rounded-full border',
                                            'border-accent' => $on,
                                            'border-line-bright' => ! $on,
                                        ])>
                                            @if ($on)
                                                <span class="size-2 rounded-full bg-accent"></span>
                                            @endif
                                        </span>

                                        <span class="min-w-0 flex-1">
                                            <span @class(['block text-sm', 'text-accent' => $on, 'text-fg-soft' => ! $on])>
                                                .{{ $converter->to() }} <span class="text-fg-faint">· {{ $converter->label() }}</span>
                                            </span>
                                            <span class="block font-mono text-xs text-fg-faint">
                                                {{ $available ? $converter->description() : __(':tool is not installed', ['tool' => $missing]) }}
                                            </span>
                                            @if ($fits < $selected->count())
                                                <span class="block text-xs text-warn">{{ __('Fits :fits of :count picked; the rest are left out.', ['fits' => $fits, 'count' => $selected->count()]) }}</span>
                                            @endif
                                        </span>

                                        @if ($hint !== null)
                                            <span class="shrink-0 font-mono text-xs text-fg-faint" title="{{ __('Going by what this conversion has made of this console\'s discs so far') }}">≈ {{ $hint }}</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    @if ($this->chosen !== null)
                        @php($options = $this->chosen->options())

                        <div class="flex flex-col gap-3">
                            <p class="kicker text-fg-faint">{{ __('Options') }}</p>

                            @if (in_array(Converter::VERIFY, $options, true))
                                <div class="flex items-center gap-2">
                                    <flux:checkbox wire:model="verify" :label="__('Verify checksum')" />
                                    <x-conversion.info :text="__('Check the written file with :tool\'s own verifier before keeping it.', ['tool' => $this->chosen->tool()])" />
                                </div>
                            @endif

                            @if (in_array(Converter::KEEP_SOURCE, $options, true))
                                <div class="flex items-center gap-2">
                                    <flux:checkbox wire:model="keepSource" :label="__('Keep source')" />
                                    <x-conversion.info :text="__('Off, the source files are deleted once the output is written and verified.')" />
                                </div>
                            @endif

                            @if (in_array(Converter::COMPRESSION, $options, true))
                                <flux:select wire:model="compression" size="sm" :label="__('Compression')">
                                    @foreach ($this->chosen->compressions() as $value => $label)
                                        <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @endif
                        </div>

                        {{-- The tool's own flags, folded away: every one has a
                             default that is right for nearly everybody. --}}
                        @if ($this->chosen->settings() !== [])
                            <div x-data="{ open: false }" wire:key="advanced-{{ $this->chosen->key() }}" class="flex flex-col gap-3">
                                <button
                                    type="button"
                                    x-on:click="open = ! open"
                                    x-bind:aria-expanded="open"
                                    class="kicker flex cursor-pointer items-center gap-1.5 text-fg-faint transition-colors hover:text-fg-soft"
                                >
                                    <flux:icon.chevron-right class="size-3 transition-transform duration-200" x-bind:class="open && 'rotate-90'" />
                                    {{ __('Advanced options') }}
                                </button>

                                <div x-show="open" x-cloak class="flex flex-col gap-3">
                                    @foreach ($this->chosen->settings() as $setting)
                                        {{-- Label and info badge, as the options above; the
                                             select keeps Flux's own field spacing. --}}
                                        <flux:field wire:key="setting-{{ $setting->key }}">
                                            <div class="flex items-center gap-2">
                                                <flux:label>{{ $setting->label }}</flux:label>
                                                <x-conversion.info :text="$setting->description" />
                                            </div>

                                            <flux:select wire:model="advanced.{{ $setting->key }}" size="sm">
                                                @foreach ($setting->choices as $value => $label)
                                                    <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                                                @endforeach
                                            </flux:select>
                                        </flux:field>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div>
                            <p class="kicker mb-1 text-fg-faint">{{ __('Writes to') }}</p>
                            <p class="truncate font-mono text-xs text-fg-soft">
                                {{ $this->writesTo }}
                            </p>
                        </div>
                    @endif

                    @php($fitting = $this->fitsChosen)

                    <flux:button variant="primary" icon="plus" wire:click="add" :disabled="$this->chosen === null">
                        {{ $selected->count() > 1 ? __('Add :count to queue', ['count' => $fitting]) : __('Add to queue') }}
                    </flux:button>
                @endif
            </section>
</div>
