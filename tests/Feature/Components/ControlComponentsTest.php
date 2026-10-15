<?php

use Illuminate\Support\Facades\Blade;

/**
 * The control primitives: each renders a real button (or link) with the
 * shared focus ring, and hands the caller's attributes through.
 */
it('renders a pill choice that sets a Livewire property, as its existing callers use it', function (): void {
    $html = Blade::render('<x-pill-choice model="mode" value="add" :label="$label" :options="$options" class="extra" />', [
        'label' => 'License ID',
        'options' => ['add' => 'Add', 'remove' => 'Remove'],
    ]);

    expect($html)
        ->toContain('role="radiogroup"')
        ->toContain('aria-label="License ID"')
        ->toContain('flex shrink-0 flex-wrap items-center')
        ->toContain('extra')
        ->toContain('wire:click="$set(\'mode\', \'add\')"')
        ->toContain('wire:click="$set(\'mode\', \'remove\')"')
        ->toContain('aria-checked="true"')
        ->toContain('aria-checked="false"')
        ->toContain('bg-accent-tint/15 text-accent')
        ->toContain('text-fg-dim hover:text-fg')
        ->toContain('px-2.75 py-1 text-xs')
        ->toContain('focus-ring');
});

it('renders a pill choice that calls a method, with icon-only options', function (): void {
    $html = Blade::render('<x-pill-choice method="setView" value="table" label="View" :icons="$icons" :options="$options" />', [
        'icons' => ['cards' => 'squares-2x2'],
        'options' => ['cards' => 'Show covers', 'table' => 'Show a list'],
    ]);

    expect($html)
        ->toContain('wire:click="setView(\'cards\')"')
        ->toContain('wire:click="setView(\'table\')"')
        ->toContain('aria-label="Show covers"')
        ->toContain('title="Show covers"')
        ->toContain('<svg')
        ->toContain('Show a list')
        ->not->toContain('$set(');
});

it('renders a pill choice driven by Alpine, full width, mono, small and lockable', function (): void {
    $html = Blade::render('<x-pill-choice alpine="destination" disabled="running" full mono size="sm" label="Destination" :options="$options" />', [
        'options' => ['dvd' => 'DVD', 'cd' => 'CD'],
    ]);

    expect($html)
        ->toContain('grid grid-flow-col auto-cols-fr')
        ->toContain('x-on:click="destination = \'dvd\'"')
        ->toContain('x-bind:aria-checked="destination === &#039;dvd&#039; ? \'true\' : \'false\'"')
        ->toContain('x-bind:class="destination === &#039;cd&#039;')
        ->toContain('x-bind:disabled="running"')
        ->toContain('px-3 py-1.5 text-sm')
        ->toContain('font-mono')
        ->not->toContain('wire:click')
        ->not->toMatch('/\saria-checked="/');
});

it('renders a copy button as an icon alone, labelled for a screen reader', function (): void {
    $html = Blade::render('<x-copy-button icon text="10.0.0.1" class="-my-1" />');

    expect($html)
        ->toContain('type="button"')
        ->toContain('aria-label="Copy"')
        ->toContain('<svg')
        ->toContain('x-text="copied ? \'Copied\' : \'\'"')
        ->toContain('-my-1')
        ->toContain('focus-ring');

    expect(Blade::render('<x-copy-button text="abc" variant="menu" label="Copy path"><b>i</b></x-copy-button>'))
        ->toContain('<b>i</b>')
        ->toContain('\'Copy path\'')
        ->not->toContain('aria-label');
});

it('renders a check in each of its states', function (): void {
    expect(Blade::render('<x-check state="on" />'))
        ->toContain('aria-hidden="true"')
        ->toContain('border-accent bg-accent-tint/20 text-accent')
        ->toContain('<svg');

    expect(Blade::render('<x-check />'))
        ->toContain('border-line-bright')
        ->not->toContain('<svg');
});

it('renders an option card, picked, as a radio with its slots', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-option-card label="CHD" description="chdman" radio active mono wire:click="selectFormat('chd')">
            <x-slot:badge><span>Current</span></x-slot:badge>
            <x-slot:meta class="text-warn">Fits 1 of 2</x-slot:meta>
            <x-slot:trailing class="font-mono">≈ 1 GB</x-slot:trailing>
        </x-option-card>
        BLADE);

    expect($html)
        ->toContain('type="button"')
        ->toContain('role="radio"')
        ->toContain('aria-checked="true"')
        ->toContain('border-accent-tint/55 bg-accent-tint/10')
        ->toContain('text-sm text-accent')
        ->toContain('size-2 rounded-full bg-accent')
        ->toContain('text-xs text-fg-faint font-mono')
        ->toContain('mt-0.5 block text-xs text-warn')
        ->toContain('shrink-0 font-mono')
        ->toContain('wire:click="selectFormat(\'chd\')"')
        ->toContain('Current');

    expect(Blade::render('<x-option-card label="Off" disabled />'))
        ->toContain('aria-pressed="false"')
        ->toMatch('/\sdisabled\s/')
        ->toContain('cursor-not-allowed border-line opacity-50')
        ->toContain('text-fg-soft');
});

it('renders a file drop, its binding attributes on the input and the rest on the zone', function (): void {
    $html = Blade::render('<x-file-drop label="Choose or drop" hint="iso · chd" accept=".iso" multiple wire:model="upload" x-on:change="pick($event)" x-on:drop.prevent="drop($event)" class="mt-2"><span>extra</span></x-file-drop>');

    expect($html)
        ->toMatch('/<label[^>]*border-dashed[^>]*mt-2[^>]*x-on:drop\.prevent="drop\(\$event\)"/')
        ->toMatch('/<input[^>]*accept="\.iso"[^>]*multiple[^>]*sr-only[^>]*wire:model="upload"[^>]*x-on:change="pick\(\$event\)"/')
        ->toContain('Choose or drop')
        ->toContain('font-mono text-xs text-fg-faint')
        ->toContain('<span>extra</span>');
});

it('renders a glass button with a label, and as an icon alone', function (): void {
    expect(Blade::render('<x-glass-button icon="ellipsis-horizontal" chevron compact wire:key="actions">Actions</x-glass-button>'))
        ->toContain('type="button"')
        ->toContain('bg-scrim/60')
        ->toContain('flex items-center gap-1.75 px-3 py-1.75 text-sm')
        ->toContain('max-sm:sr-only')
        ->toContain('max-sm:hidden')
        ->toContain('focus-ring')
        ->toContain('wire:key="actions"')
        ->toContain('Actions');

    expect(Blade::render('<x-glass-button icon-only label="Actions for PS2" />'))
        ->toContain('aria-label="Actions for PS2"')
        ->toContain('grid size-8 shrink-0 place-items-center p-0');
});

it('renders an icon button, labelled, as a button or a link', function (): void {
    expect(Blade::render('<x-icon-button icon="x-mark" label="Unlink" tone="danger" size="sm" wire:click="unlink(1)" />'))
        ->toContain('<button')
        ->toContain('type="button"')
        ->toContain('aria-label="Unlink"')
        ->toContain('title="Unlink"')
        ->toContain('size-5')
        ->toContain('hover:text-danger')
        ->toContain('focus-ring')
        ->toContain('wire:click="unlink(1)"');

    expect(Blade::render('<x-icon-button icon="arrow-top-right-on-square" label="Open" href="/x" />'))
        ->toContain('<a')
        ->toContain('href="/x"')
        ->toContain('size-7')
        ->toContain('hover:text-fg-soft')
        ->not->toContain('type="button"');
});

it('renders the call-to-action button as a submit with its icons', function (): void {
    $html = Blade::render('<x-cta-button icon-trailing="arrow-right" data-test="create-account-button">Create account</x-cta-button>');

    expect($html)
        ->toContain('type="submit"')
        ->toContain('border-accent-tint/55 bg-accent-tint/10 px-4 py-3 text-base text-accent')
        ->toContain('data-test="create-account-button"')
        ->toContain('<svg')
        ->toContain('Create account');
});

it('renders a pick-all button around a check, labelled and disableable', function (): void {
    $html = Blade::render('<x-pick-all state="some" label="Select all" wire:click="toggleAll" :disabled="true" />');

    expect($html)
        ->toContain('type="button"')
        ->toContain('aria-label="Select all"')
        ->toContain('title="Select all"')
        ->toContain('disabled:cursor-not-allowed disabled:opacity-50')
        ->toContain('wire:click="toggleAll"')
        ->toContain('disabled="disabled"')
        ->toContain('border-accent bg-accent-tint/20');
});

it('renders a live-system listener that calls the method on its signals and after a timeout', function (): void {
    $html = Blade::render('<x-live-system signal="discovery" call="checkSearch" :timeout="9000" wire:key="search-1" class="mt-3">Waiting</x-live-system>');

    expect($html)
        ->toMatch('/^\s*<div\s/')
        ->toContain('this.$wire[\'checkSearch\']()')
        ->toContain('live.system(what, run)')
        ->toContain('discovery')
        ->toContain('setTimeout(run, 9000)')
        ->toContain('clearTimeout(this.timer)')
        ->toContain('wire:key="search-1"')
        ->toContain('class="mt-3"')
        ->toContain('Waiting');

    expect(Blade::render('<x-live-system as="section" :signal="[\'activity\', \'conversion\']" call="$refresh" />'))
        ->toMatch('/^\s*<section\s/')
        ->toContain('this.$wire[\'$refresh\']()')
        ->toContain('activity')
        ->toContain('conversion')
        ->not->toContain('setTimeout');
});

it('renders a step ring in each state, and the settings step through it', function (): void {
    expect(Blade::render('<x-step-ring :number="2" />'))
        ->toContain('border-accent-tint/55 bg-accent-tint/15 text-accent')
        ->toContain('2');

    expect(Blade::render('<x-step-ring :number="1" state="done" />'))
        ->toContain('border-line-bright text-fg-muted')
        ->toContain('<svg');

    expect(Blade::render('<x-step-ring :number="4" state="todo" class="extra" />'))
        ->toContain('border-line-bright text-fg-faint')
        ->toContain('extra');

    expect(Blade::render('<x-settings.step number="3" title="Pick the share">Body</x-settings.step>'))
        ->toContain('rounded-full border')
        ->toContain('mt-px')
        ->toContain('Pick the share')
        ->toContain('Body');
});

it('renders a text link as a button with a chevron, or as a link', function (): void {
    expect(Blade::render('<x-text-link chevron="open" x-on:click="open = ! open">Show</x-text-link>'))
        ->toContain('type="button"')
        ->toContain('cursor-pointer text-accent hover:underline focus-ring')
        ->toContain('x-bind:aria-expanded="open"')
        ->toContain('-rotate-180')
        ->toContain('x-on:click="open = ! open"')
        ->toContain('Show');

    expect(Blade::render('<x-text-link href="/settings">Settings</x-text-link>'))
        ->toContain('<a')
        ->toContain('href="/settings"')
        ->not->toContain('type="button"')
        ->not->toContain('aria-expanded');
});
