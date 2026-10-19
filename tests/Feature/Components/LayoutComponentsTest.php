<?php

use App\Support\Console;
use App\Support\CoverGeometry;
use Illuminate\Support\Facades\Blade;

/**
 * The layout and structure primitives: each renders its canonical look and
 * hands the caller's attributes through to its root.
 */
it('renders a panel, with the header strip only when it has something to hold', function () {
    $html = Blade::render(<<<'BLADE'
        <x-panel title="Queue" tone="sunken" divided as="ul" wire:key="queue">
            <x-slot:actions>
                <button>Clear finished</button>
            </x-slot:actions>

            <li>One</li>
        </x-panel>
        BLADE);

    expect($html)
        ->toContain('overflow-hidden rounded-xl border border-line bg-sunken')
        ->toContain('wire:key="queue"')
        ->toContain('border-b border-raised px-4.5 py-3.5')
        ->toContain('<p class="kicker text-fg-faint">Queue</p>')
        ->toContain('ml-auto')
        ->toContain('Clear finished')
        ->toContain('class="divide-y divide-raised"')
        ->toContain('<li>One</li></ul>');

    expect(Blade::render('<x-panel padded class="mt-2">Body</x-panel>'))
        ->toContain('bg-surface p-5 mt-2')
        ->not->toContain('border-b border-raised');

    expect(Blade::render('<x-panel title="Fetch" align="end">Body</x-panel>'))
        ->toContain('flex flex-wrap gap-3 border-b border-raised px-4.5 py-3.5 items-end')
        ->not->toContain('align=');

    expect($html)->toContain('flex flex-wrap gap-3 border-b border-raised px-4.5 py-3.5 items-center');
});

it('renders a standalone panel header with its title and actions', function () {
    $html = Blade::render(<<<'BLADE'
        <x-panel.header title="Fetch another region" align="end" class="extra">
            <span>Extra</span>

            <x-slot:actions>
                <button>Fetch</button>
            </x-slot:actions>
        </x-panel.header>
        BLADE);

    expect($html)
        ->toContain('flex flex-wrap gap-3 border-b border-raised px-4.5 py-3.5 items-end extra')
        ->not->toContain('items-center gap-3')
        ->toContain('Fetch another region')
        ->toContain('<span>Extra</span>')
        ->toContain('<div class="ml-auto flex items-center gap-2">');
});

it('renders a page header with its kicker, meta, description and actions', function () {
    $html = Blade::render(<<<'BLADE'
        <x-page-header kicker="Library" title="Consoles" description="Every console added." class="extra">
            <x-slot:meta>3 consoles</x-slot:meta>

            <x-slot:actions>
                <button>Add console</button>
            </x-slot:actions>
        </x-page-header>
        BLADE);

    expect($html)
        ->toContain('mb-6 flex flex-wrap items-end justify-between gap-4 extra')
        ->toContain('<p class="kicker mb-1.5 text-fg-faint">Library</p>')
        ->toContain('<h1 class="text-display font-medium tracking-display text-fg-bright">Consoles</h1>')
        ->toContain('<p class="mt-1.5 font-mono text-xs text-fg-faint">3 consoles</p>')
        ->toContain('<p class="mt-1.5 text-sm text-fg-muted">Every console added.</p>')
        ->toContain('Add console');

    expect(Blade::render('<x-page-header title="Plain" />'))
        ->not->toContain('kicker')
        ->not->toContain('font-mono');
});

it('renders a section header with its aside', function () {
    $html = Blade::render(<<<'BLADE'
        <x-section-header title="Latest achievements" wire:key="latest">
            <x-slot:aside>
                <p>+5 pts this week</p>
            </x-slot:aside>
        </x-section-header>
        BLADE);

    expect($html)
        ->toContain('mb-3.5 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2')
        ->toContain('<h2 class="text-lg font-medium text-fg-bright">Latest achievements</h2>')
        ->toContain('+5 pts this week')
        ->toContain('wire:key="latest"');
});

it('renders a stat as a dt and dd pair, mono at md and sans with a bar at lg', function () {
    expect(Blade::render('<x-stat label="Unidentified" tone="warn" wire:key="unidentified">3</x-stat>'))
        ->toContain('<dt class="kicker text-fg-faint">Unidentified</dt>')
        ->toContain('mt-1 text-lg font-mono text-warn')
        ->toContain('wire:key="unidentified"');

    $html = Blade::render(<<<'BLADE'
        <x-stat label="Points" size="lg" :percent="42">
            42

            <x-slot:sub>of 100</x-slot:sub>
        </x-stat>
        BLADE);

    expect($html)
        ->toContain('mt-1.5 text-[19px] font-medium tracking-display text-fg-bright')
        ->not->toContain('font-mono')
        ->toContain('<dd class="mt-1 text-sm text-fg-dim">of 100</dd>')
        ->toContain('role="progressbar"')
        ->toContain('h-[3px]')
        ->toContain('width: 42%');

    $held = Blade::render('<x-stat label="Publisher" :title="$value" truncate class="min-w-0">{{ $value }}</x-stat>', ['value' => 'Capcom & Clover']);

    expect($held)
        ->toMatch('/<dd\s+title="Capcom &amp; Clover"\s+class="tabular-nums truncate mt-1 text-lg/')
        ->toContain('<div class="min-w-0">');

    expect(Blade::render('<x-stat label="Size">4 GB</x-stat>'))
        ->not->toContain('truncate')
        ->not->toContain('title=');
});

it('renders a key/value row', function () {
    $html = Blade::render('<x-kv-row label="PS2" mono wire:key="idx-1">never</x-kv-row>');

    expect($html)
        ->toContain('flex items-baseline gap-3 border-b border-raised pb-2.5 last:border-0 last:pb-0')
        ->toContain('<dt class="text-xs text-fg-soft font-mono">PS2</dt>')
        ->toContain('<dd class="ml-auto font-mono text-xs text-fg-dim tabular-nums">never</dd>')
        ->toContain('wire:key="idx-1"');
});

it('renders a modal with its heading, form, footer and opening event', function () {
    $html = Blade::render(<<<'BLADE'
        <x-modal
            name="disc-key"
            width="2xl"
            heading="Add disc key"
            subheading="game.iso"
            mono
            submit="save"
            event="disc-key"
            then="$wire.open($event.detail.fileId)"
            wire:close="closed"
            :dismissible="false"
            x-data="{ open: false }"
        >
            <p>Body</p>

            <x-slot:footer-start>
                <span>Powered by ScreenScraper</span>
            </x-slot:footer-start>

            <x-slot:footer>
                <flux:button type="submit" variant="primary">Check and save</flux:button>
            </x-slot:footer>
        </x-modal>
        BLADE);

    expect($html)
        ->toContain('x-on:disc-key.window="$flux.modal(\'disc-key\').show(); $wire.open($event.detail.fileId)"')
        ->toContain('data-modal="disc-key"')
        ->toContain('w-full max-w-2xl')
        ->toContain('wire:close="closed"')
        ->toContain('disable-click-outside')
        ->toContain('wire:submit="save"')
        ->toContain('class="flex flex-col gap-5" x-data="{ open: false }"')
        ->toContain('Add disc key')
        ->toContain('<p class="mt-1 truncate font-mono text-xs text-fg-faint">game.iso</p>')
        ->toContain('justify-between')
        ->toContain('Powered by ScreenScraper')
        ->toContain('Check and save');

    // Cancel comes first, the primary action last.
    expect(strpos($html, 'Cancel'))->toBeLessThan(strpos($html, 'Check and save'));

    expect(Blade::render('<x-modal name="steps" :cancel="false">Only the body</x-modal>'))
        ->toContain('<div  class="flex flex-col gap-5">')
        ->toContain('max-w-lg')
        ->not->toContain('Cancel')
        ->not->toContain('<form');
});

it('renders a modal step for a modal that swaps its content', function () {
    $html = Blade::render(<<<'BLADE'
        <x-modal.content heading="How is the folder arranged?" subheading="It changes what is read as a game." :cancel="false">
            <p>Layouts</p>

            <x-slot:footer>
                <button>Back</button>
            </x-slot:footer>
        </x-modal.content>
        BLADE);

    expect($html)
        ->toContain('flex flex-col gap-5')
        ->toContain('How is the folder arranged?')
        ->toContain('data-flux-text')
        ->toContain('It changes what is read as a game.')
        ->toContain('flex items-center gap-2 justify-end')
        ->toContain('<button>Back</button>')
        ->not->toContain('data-flux-modal-close');
});

it('renders a search modal with its input, results and empty line', function () {
    $html = Blade::render(<<<'BLADE'
        <x-search-modal name="link-game" heading="Link a game" placeholder="Search games by title" model="query" empty="No game matches that." :has-results="false" />
        BLADE);

    expect($html)
        ->toContain('data-modal="link-game"')
        ->toContain('max-w-md')
        ->toContain('wire:model.live.debounce.200ms="query"')
        ->toContain('placeholder="Search games by title"')
        ->toContain('max-h-80 overflow-y-auto')
        ->toContain('px-4 py-8 text-center text-sm text-fg-faint')
        ->toContain('No game matches that.')
        ->not->toContain('Cancel');

    expect(Blade::render('<x-search-modal name="link-doc" heading="Link a doc" empty="Nothing."><button>Row</button></x-search-modal>'))
        ->toContain('<button>Row</button>')
        ->not->toContain('Nothing.');
});

it('renders a preview list with how many more and its notes', function () {
    $html = Blade::render(<<<'BLADE'
        <x-preview-list :total="12" summary="12 games will be moved." class="extra">
            <li>One</li>

            <x-slot:notes>
                <li>1 game is already in a folder.</li>
            </x-slot:notes>
        </x-preview-list>
        BLADE);

    expect($html)
        ->toContain('flex flex-col gap-3 extra')
        ->toContain('<p class="text-sm text-fg-soft">12 games will be moved.</p>')
        ->toContain('flex flex-col divide-y divide-line rounded-lg border border-line bg-sunken')
        ->toContain('<li>One</li>')
        ->toContain('and 2 more.')
        ->toContain('1 game is already in a folder.');

    expect(Blade::render('<x-preview-list :total="0" summary="Nothing to move." />'))
        ->toContain('Nothing to move.')
        ->not->toContain('<ul');
});

it('renders a field with its label, info, description and error', function () {
    $html = Blade::render(<<<'BLADE'
        <x-field label="Machine" info="A name like batocera." description="Where the share is." name="host" wire:key="host">
            <input id="host" />
        </x-field>
        BLADE);

    expect($html)
        ->toContain('data-flux-field')
        ->toContain('wire:key="host"')
        ->toContain('Machine')
        ->toContain('aria-label="A name like batocera."')
        ->toContain('Where the share is.')
        ->toContain('<input id="host" />')
        ->toContain('data-flux-error');

    // flux:field names data-flux-description in its own selectors, so check
    // for the parts themselves rather than the attribute.
    expect(Blade::render('<x-field label="Title"><input /></x-field>'))
        ->not->toContain('aria-label="A name like batocera."')
        ->not->toContain('Where the share is.')
        ->not->toContain('data-flux-error');
});

it('renders a field group under its kicker with a hint', function () {
    $html = Blade::render('<x-field-group label="Console" hint="Decides the folder." class="extra"><div>Picker</div></x-field-group>');

    expect($html)
        ->toContain('class="extra"')
        ->toContain('<p class="kicker mb-2 text-fg-faint">Console</p>')
        ->toContain('<p class="mb-2 text-xs text-fg-faint">Decides the folder.</p>')
        ->toContain('<div>Picker</div>');
});

it('renders a path preview, a dash while it has nothing to show', function () {
    $html = Blade::render('<x-path-preview value="docs/ps2/memory-cards.md" icon="folder" class="extra" />');

    expect($html)
        ->toContain('class="extra"')
        ->toContain('Writes to')
        ->toContain('rounded-lg border border-line-input bg-sunken px-3 py-2 font-mono text-xs text-fg-dim')
        ->toContain('title="docs/ps2/memory-cards.md"')
        ->toContain('<span class="min-w-0 truncate">docs/ps2/memory-cards.md</span>')
        ->toContain('<svg');

    expect(Blade::render('<x-path-preview label="Inserts" value="" />'))
        ->toContain('Inserts')
        ->toContain('—');
});

it('renders a backdrop with its fade, fallback and scanlines', function () {
    $html = Blade::render('<x-backdrop src="/art.jpg" fade="hero-y" class="extra" />');

    expect($html)
        ->toContain('aria-hidden="true"')
        ->toContain('pointer-events-none inset-0 absolute extra')
        ->toContain("background-image: url('/art.jpg'); background-position: 50% 28%")
        ->toContain('hero-fade-y')
        ->toContain('scanlines');

    expect(Blade::render('<x-backdrop src="/art.jpg" fade="art-l" position="center" fixed><div class="glow"></div></x-backdrop>'))
        ->toContain('fixed art-fade-l')
        ->toContain('background-position: center')
        ->toContain('<div class="glow"></div>');

    expect(Blade::render('<x-backdrop fade="hero-y" fallback />'))
        ->toContain('bg-[linear-gradient(165deg,var(--color-raised),var(--color-sunken))]')
        ->not->toContain('scanlines');
});

it('renders a cover frame with its art, or the console icon in its place', function () {
    $console = Console::tryFrom('ps2');

    $html = Blade::render(<<<'BLADE'
        <x-cover-frame src="/cover.jpg" size="lg" :console="$console" alt="Okami" wire:key="cover">
            <x-slot:overlay>
                <span class="pick"></span>
            </x-slot:overlay>
        </x-cover-frame>
        BLADE, ['console' => $console]);

    expect($html)
        ->toContain('aspect-ratio: '.CoverGeometry::aspect($console))
        ->toContain('w-full rounded-xl border-line-strong')
        ->toContain('src="/cover.jpg"')
        ->toContain('alt="Okami"')
        ->toContain('loading="lazy"')
        ->toContain('x-on:load="fit()"')
        ->toContain('<span class="pick"></span>')
        ->toContain('wire:key="cover"');

    expect(Blade::render('<x-cover-frame size="lg" :console="$console" label="Unidentified" />', ['console' => $console]))
        ->toContain('src="'.$console->fileIcon.'"')
        ->toContain('opacity-25')
        ->toContain('Unidentified');

    expect(Blade::render('<x-cover-frame size="hero" :console="$console" />', ['console' => $console]))
        ->toContain('height: '.CoverGeometry::HERO_HEIGHT.'px; width: '.CoverGeometry::heroWidth($console).'px')
        ->toContain('shadow-lift');

    expect(Blade::render('<x-cover-frame size="hero" src="/cover.jpg" lightbox="covers/okami.jpg" />'))
        ->toContain('data-lightbox="covers/okami.jpg"')
        ->not->toContain('loading="lazy"');

    expect(Blade::render('<x-cover-frame size="md" />'))
        ->toContain('size-12 rounded-md')
        ->toContain('<svg');

    $fallback = Blade::render('<x-cover-frame as="span" size="sm" icon="document-text" />');

    expect($fallback)
        ->toStartWith('<span')
        ->toContain('size-8 rounded-md border-line-strong')
        ->toContain('size-4 text-fg-muted')
        ->not->toContain('<div')
        ->not->toContain('text-fg-faint');

    expect(Blade::render('<x-cover-frame size="sm" src="/thumb.jpg" icon="document-text" />'))
        ->toContain('src="/thumb.jpg"')
        ->not->toContain('<svg');
});

it('renders a region flag, or its code where there is no flag', function () {
    expect(Blade::render('<x-region-flag icon="/flags/eu.png" label="Europe" size="md" class="extra" />'))
        ->toContain('src="/flags/eu.png"')
        ->toContain('alt="Europe"')
        ->toContain('title="Europe"')
        ->toContain('border border-line-input h-4.5 extra');

    expect(Blade::render('<x-region-flag code="wor" label="World" />'))
        ->toContain('title="World"')
        ->toContain('font-mono text-xs text-fg-faint uppercase')
        ->toContain('>wor</span>');

    expect(trim(Blade::render('<x-region-flag />')))->toBe('');
});

it('renders a band with its gutters and top padding', function () {
    expect(Blade::render('<x-band class="lg:pt-10" wire:key="details">Body</x-band>'))
        ->toContain('<section class="relative z-1 px-4 lg:px-8 pt-4.5 lg:pt-10" wire:key="details">')
        ->toContain('Body');

    expect(Blade::render('<x-band pad="lg">Body</x-band>'))
        ->toContain('pt-6.5');
});
