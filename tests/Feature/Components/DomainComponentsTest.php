<?php

use Illuminate\Support\Facades\Blade;

/**
 * The domain components: settings cards and their parts, the sidebar's
 * meters and nested rows, and the pieces of a game file card. Each renders
 * what its call sites drew by hand.
 */
it('renders a settings card with an icon header, a badge and actions', function () {
    $html = Blade::render(<<<'BLADE'
        <x-settings.card icon="trophy" :title="'Account'" :description="'Why it matters.'" class="lg:col-span-6">
            <x-slot:badge>
                <x-settings.linked-badge :linked="true" />
            </x-slot:badge>
            <x-slot:actions>
                <span>ACTIONS</span>
            </x-slot:actions>
            BODY
        </x-settings.card>
        BLADE);

    expect($html)
        ->toContain('rounded-xl border border-line bg-surface p-5 lg:col-span-6')
        ->toContain('mb-4 flex items-center gap-2.5')
        ->toContain('size-[17px]')
        ->toContain('Account')
        ->toContain('Linked')
        ->toContain('ACTIONS')
        ->toContain('mb-4 text-sm text-fg-soft')
        ->toContain('BODY');
});

it('renders a settings card with a kicker header, closer to its description than to bare content', function () {
    $described = Blade::render('<x-settings.card :kicker="\'Profile\'" :description="\'Your name.\'">BODY</x-settings.card>');
    $bare = Blade::render('<x-settings.card :kicker="\'Saved\'">BODY</x-settings.card>');

    expect($described)->toContain('kicker text-fg-faint')->toContain('mb-1')->toContain('Your name.');
    expect($bare)->toContain('mb-3')->not->toContain('text-fg-soft');
});

it('renders a settings choice row with its control beside the text', function () {
    $html = Blade::render('<x-settings.choice-row :title="\'Console order\'" :description="\'How they sort.\'"><span>CONTROL</span></x-settings.choice-row>');

    expect($html)
        ->toContain('flex flex-wrap items-center justify-between gap-4')
        ->toContain('min-w-0 flex-1 basis-64')
        ->toContain('text-sm font-medium text-fg-bright')
        ->toContain('mt-1 text-sm text-fg-muted')
        ->toContain('CONTROL');
});

it('renders the linked badge in the accent and the unlinked one muted', function () {
    $linked = Blade::render('<x-settings.linked-badge :linked="true" />');
    $unlinked = Blade::render('<x-settings.linked-badge :linked="false" />');

    expect($linked)->toContain('Linked')->toContain('text-accent')->toContain('bg-accent-tint/10');
    expect($unlinked)->toContain('Not linked')->toContain('text-fg-faint');
});

it('renders a secret input that says a value is stored and offers to forget it', function () {
    $stored = Blade::render('<x-settings.secret-input model="apiKey" :label="\'API key\'" :stored="true" :placeholder="\'From Settings\'" forget="forgetKey" :forget-label="\'Remove stored key\'" />');
    $empty = Blade::render('<x-settings.secret-input model="apiKey" :label="\'API key\'" :placeholder="\'From Settings\'" forget="forgetKey" />');

    expect($stored)
        ->toContain('type="password"')
        ->toContain('wire:model="apiKey"')
        ->toContain('placeholder="Stored — type to replace it"')
        ->toContain('Kept encrypted in the database and never shown again once saved.')
        ->toContain('wire:click="forgetKey"')
        ->toContain('Remove stored key');

    expect($empty)
        ->toContain('placeholder="From Settings"')
        ->not->toContain('wire:click="forgetKey"');
});

it('renders a settings action with its hint under the button', function () {
    $html = Blade::render('<x-settings.action :hint="\'Queues the download.\'" class="mt-4"><button type="button">Refresh</button></x-settings.action>');

    expect($html)
        ->toContain('<div class="mt-4">')
        ->toContain('Refresh')
        ->toContain('mt-1.5 text-xs text-fg-faint')
        ->toContain('Queues the download.');
});

it('renders a settings save bound to its form, saying Continue in the wizard', function () {
    $save = Blade::render('<x-settings.save form="media-settings" />');
    $onboarding = Blade::render('<x-settings.save form="media-settings" :onboarding="true" />');
    $labelled = Blade::render('<x-settings.save form="media-settings" :onboarding="true" :label="\'Finish setup\'" />');

    expect($save)->toContain('type="submit"')->toContain('form="media-settings"')->toContain('Save');
    expect($onboarding)->toContain('Continue');
    expect($labelled)->toContain('Finish setup')->not->toContain('Continue');
});

it('renders an icon note as a list item with a micro icon', function () {
    $html = Blade::render('<ul><x-icon-note icon="folder">Point Folder above roms/.</x-icon-note></ul>');

    expect($html)
        ->toContain('<li class="flex gap-2.5">')
        ->toContain('mt-0.5 size-4 shrink-0 text-fg-muted')
        ->toContain('Point Folder above roms/.');
});

it('renders a sidebar meter as a toggle, a plain heading, or a disabled one', function () {
    $open = Blade::render(<<<'BLADE'
        <x-sidebar.meter :label="'Scraper'" :toggle-label="'Show the allowance'" x-data="{ open: false }" class="mb-3">
            <x-slot:figure>12</x-slot:figure>
            <x-slot:summary><p>SUMMARY</p></x-slot:summary>
            <p>FOLDED</p>
        </x-sidebar.meter>
        BLADE);
    $plain = Blade::render('<x-sidebar.meter :label="\'Storage\'" :collapsible="false"><x-slot:figure>1 GB</x-slot:figure></x-sidebar.meter>');
    $disabled = Blade::render('<x-sidebar.meter :label="\'Scraper\'" :disabled="true"><x-slot:figure>—</x-slot:figure></x-sidebar.meter>');

    expect($open)
        ->toContain('x-data="{ open: false }"')
        ->toContain('x-on:click="open = ! open"')
        ->toContain('aria-label="Show the allowance"')
        ->toContain('-rotate-180')
        ->toContain('SUMMARY')
        ->toContain('x-show="open"')
        ->toContain('FOLDED');

    expect($plain)->toContain('1 GB')->not->toContain('<button')->not->toContain('-rotate-180');
    expect($disabled)->toMatch('/\sdisabled\s/')->not->toContain('-rotate-180')->not->toContain('x-show');
});

it('renders a sidebar meter row with a bar, or the bar it is given', function () {
    $row = Blade::render('<x-sidebar.meter-row :label="\'Scans\'" :percent="40">3</x-sidebar.meter-row>');
    $quiet = Blade::render('<x-sidebar.meter-row :label="\'Failed\'" :quiet="true">0<x-slot:bar><p>BAR</p></x-slot:bar></x-sidebar.meter-row>');

    expect($row)->toContain('Scans')->toContain('text-fg-dim')->toContain('width: 40%');
    expect($quiet)->toContain('text-fg-faint">0')->toContain('BAR')->not->toContain('role="progressbar"');
});

it('renders a sidebar sub item, lit when current', function () {
    $current = Blade::render('<x-sidebar.sub-item href="/consoles/ps2" :current="true" image="/ps2.svg" :count="0" wire:navigate>PlayStation 2</x-sidebar.sub-item>');
    $other = Blade::render('<x-sidebar.sub-item href="/tools/conversion" icon="arrows-right-left">Conversion</x-sidebar.sub-item>');

    expect($current)
        ->toContain('href="/consoles/ps2"')
        ->toContain('aria-current="page"')
        ->toContain('shadow-rail')
        ->toContain('src="/ps2.svg"')
        ->toContain('ms-auto font-mono text-xs text-fg-faint">0')
        ->toContain('wire:navigate');

    expect($other)
        ->toContain('text-fg-cool')
        ->toContain('<svg')
        ->not->toContain('aria-current')
        ->not->toContain('ms-auto');
});

it('renders a game file lineage line with its tracks, time and trailing slot', function () {
    $html = Blade::render('<x-game-file.lineage :verb="\'Converted from\'" :name="\'Game.cue\'" :more="2" :when="\'3 days ago\'" class="mt-1.5"><button type="button">Show</button></x-game-file.lineage>');
    $bare = Blade::render('<x-game-file.lineage :verb="\'Converted to\'" :name="\'Game.chd\'" />');

    expect($html)
        ->toContain('mt-1.5')
        ->toContain('Converted from')
        ->toContain('Game.cue')
        ->toContain('+ 2 tracks')
        ->toContain('· 3 days ago')
        ->toContain('Show');

    expect($bare)->toContain('Game.chd')->not->toContain('tracks')->not->toContain('·');
});

it('renders a game file folder, and nothing for the console root', function () {
    $html = Blade::render('<x-game-file.folder path="Disc 1/sub" class="mt-0.5" />');
    $root = Blade::render('<x-game-file.folder path="" />');

    expect($html)->toContain('font-mono text-xs break-all text-fg-faint mt-0.5')->toContain('Disc 1/sub');
    expect(trim($root))->toBe('');
});
