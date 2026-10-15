@props(['row', 'archived' => false])

@php
    use App\Enums\DecryptState;

    $decrypt = Arr::get($row, 'decrypt');
@endphp

{{-- A file's badges, in its card's footer, live or archived. Each is
     named on hover; one with nothing to say is left out. --}}
<div {{ $attributes->class('flex flex-wrap items-center gap-1.5 font-mono text-xs') }}>
    {{-- The disc's own name for itself: on an OPL drive the file can be
         called anything and the serial cannot. --}}
    @if ($row['licenseId'])
        <x-game-file.badge :archived="$archived" :tip="__('License ID')">{{ $row['licenseId'] }}</x-game-file.badge>
    @endif

    {{-- A flag where one depicts the region, as on the game card; else
         its code, with the region named in the tooltip. --}}
    @if ($row['region'] !== null)
        @if ($row['regionIcon'] !== null)
            <x-game-file.badge :archived="$archived" :tip="__('Region')">
                <img src="{{ $row['regionIcon'] }}" alt="{{ $row['regionLabel'] }}" @class(['w-4.5 border border-line-input', 'opacity-70' => $archived]) />
                <span aria-hidden="true">{{ $row['regionLabel'] }}</span>
            </x-game-file.badge>
        @else
            <x-game-file.badge :archived="$archived" :tip="__('Region: :name', ['name' => $row['regionLabel']])">{{ Str::upper($row['region']) }}</x-game-file.badge>
        @endif
    @endif

    @if ($row['scrapes'] !== null)
        <x-game-file.badge :archived="$archived" :tip="__('How many times this very dump has been scraped on ScreenScraper. Send to picks the most scraped version of a region.')">
            {{ __(':count scrapes', ['count' => $row['scrapes']]) }}
        </x-game-file.badge>
    @endif

    {{-- What the provider says the dump is, whatever its name does. A beta,
         a hack or a translation is never what Send to picks. --}}
    @foreach ($row['flags'] as $flag)
        <x-game-file.badge :tip="__('Flagged by ScreenScraper')" warn :archived="$archived">
            {{ __(Str::headline($flag === 'trad' ? 'translation' : $flag)) }}
        </x-game-file.badge>
    @endforeach

    <x-game-file.badge :archived="$archived" :tip="__('Role')">{{ $row['role'] }}</x-game-file.badge>

    @if ($row['disc'] !== '—')
        <x-game-file.badge :archived="$archived" :tip="__('Disc number')">{{ __('Disc :number', ['number' => $row['disc']]) }}</x-game-file.badge>
    @endif

    @if ($row['size'] !== '—')
        <x-game-file.badge :archived="$archived" :tip="__('Size')" :class="Arr::toCssClasses(['text-fg-bright!' => ! $archived])">{{ $row['size'] }}</x-game-file.badge>
    @endif

    <x-game-file.badge :archived="$archived" :tip="__('Format')">{{ $row['format'] }}</x-game-file.badge>

    <x-game-file.badge :archived="$archived" :tip="__('When it was added to the library')">{{ __('Added :when', ['when' => $row['added']]) }}</x-game-file.badge>

    {{-- Whether the disc will start, in DecryptState::tone()'s colours but
         a footer badge's shape, held apart at the right end from the facts
         about the dump. Archived rows have no state to show. --}}
    @if ($decrypt !== null)
        <div class="ml-auto flex">
            <x-game-file.badge
                :tip="$decrypt->hint()"
                :tone="$decrypt->tone()"
                :dashed="$decrypt->dashed()"
                :class="Arr::toCssClasses(['animate-pulse' => $decrypt === DecryptState::Decrypting])"
            >
                <x-decrypt.icon :state="$decrypt" />
                <span>{{ $decrypt->label() }}</span>
            </x-game-file.badge>
        </div>
    @endif
</div>
