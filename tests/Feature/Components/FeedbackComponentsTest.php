<?php

use App\Enums\ConversionStatus;
use App\Enums\DecryptState;
use Illuminate\Support\Facades\Blade;

/**
 * The feedback and status primitives: each renders its canonical look and
 * hands the caller's attributes through to its root.
 */
it('renders a badge in its tone, dashed and filled, with an icon and the caller\'s attributes', function () {
    $html = Blade::render('<x-badge tone="accent" icon="lock-closed" dashed fill wire:click="go" class="extra">Hi</x-badge>');

    expect($html)
        ->toContain('tracking-kicker uppercase')
        ->toContain('border-accent-tint/55 text-accent')
        ->toContain('border-dashed')
        ->toContain('bg-accent-tint/10')
        ->toContain('wire:click="go"')
        ->toContain('extra')
        ->toContain('<svg')
        ->toContain('Hi');

    expect(Blade::render('<x-badge size="sm">Sm</x-badge>'))
        ->toContain('h-5.5')
        ->toContain('normal-case')
        ->not->toContain('uppercase');

    expect(Blade::render('<x-badge tone="warn" tip="Careful">Tip</x-badge>'))
        ->toContain('data-flux-tooltip')
        ->toContain('cursor-help')
        ->toContain('max-w-xs font-sans')
        ->toContain('Careful');
});

it('renders a chip as a pressed toggle button with a count', function () {
    $html = Blade::render('<x-chip active :count="3" mono wire:click="toggle(1)">PS2</x-chip>');

    expect($html)
        ->toContain('<button')
        ->toContain('type="button"')
        ->toContain('aria-pressed="true"')
        ->toContain('border-accent-tint/55 bg-accent-tint/10 text-accent')
        ->toContain('font-mono')
        ->toContain('focus-ring')
        ->toContain('wire:click="toggle(1)"')
        ->toContain('ml-1 font-mono text-fg-faint');

    expect(Blade::render('<x-chip>Off</x-chip>'))
        ->toContain('aria-pressed="false"')
        ->toContain('border-line-input text-fg-dim');

    expect(Blade::render('<x-chip dashed>Show all</x-chip>'))
        ->toContain('border-dashed border-line-bright')
        ->not->toContain('aria-pressed');
});

it('renders a notice in its tone, with a note, a count and a busy spinner', function () {
    $html = Blade::render('<x-notice tone="warn" count="2 / 5" x-on:click="hide">Message<x-slot:note>Small print</x-slot:note></x-notice>');

    expect($html)
        ->toContain('flex flex-wrap items-start border')
        ->toContain('border-warn/40 bg-warn/10 text-warn')
        ->toContain('Message')
        ->toContain('mt-1 text-xs opacity-80')
        ->toContain('Small print')
        ->toContain('w-full max-w-full font-mono text-xs sm:ml-auto sm:w-auto sm:shrink-0')
        ->toContain('2 / 5')
        ->toContain('x-on:click="hide"');

    expect(Blade::render('<x-notice tone="accent" busy>Busy</x-notice>'))
        ->toContain('border-accent-tint/55 bg-accent-tint/10 text-accent')
        ->toContain('animate-spin');
});

it('renders an empty state as a dashed block or an inline line', function () {
    $html = Blade::render('<x-empty-state title="Nothing" hint="Scan a console" wire:key="empty"><button>Add</button></x-empty-state>');

    expect($html)
        ->toContain('rounded-xl border border-dashed border-line-input px-6 py-10 text-center')
        ->toContain('text-sm text-fg-soft">Nothing')
        ->toContain('mt-1 text-sm text-fg-faint">Scan a console')
        ->toContain('<div class="mt-4"><button>Add</button></div>')
        ->toContain('wire:key="empty"');

    expect(Blade::render('<x-empty-state variant="inline" title="None here" />'))
        ->toContain('px-4 py-8 text-center text-sm text-fg-faint')
        ->toContain('None here')
        ->not->toContain('border-dashed');
});

it('renders a status dot for each state', function () {
    expect(Blade::render('<x-status state="ok" class="extra">Online</x-status>'))
        ->toContain('bg-accent shadow-glow')
        ->toContain('Online')
        ->toContain('extra');

    expect(Blade::render('<x-status state="pending">Checking</x-status>'))->toContain('animate-pulse bg-fg-faint');
    expect(Blade::render('<x-status state="error">Offline</x-status>'))->toContain('bg-danger');
    expect(Blade::render('<x-status state="idle" />'))->toContain('bg-line-strong');
});

it('renders a progress bar clamped to its track, or bound to an Alpine expression', function () {
    $html = Blade::render('<x-progress-bar :percent="140" tone="danger" size="md" class="flex-1" />');

    expect($html)
        ->toContain('role="progressbar"')
        ->toContain('aria-valuenow="100"')
        ->toContain('h-1.5')
        ->toContain('flex-1')
        ->toContain('bg-danger')
        ->toContain('style="width: 100%"');

    expect(Blade::render('<x-progress-bar :percent="40" tone="warn" size="xs" />'))
        ->toContain('h-[3px]')
        ->toContain('bg-warn')
        ->toContain('style="width: 40%"');

    expect(Blade::render('<x-progress-bar x-percent="progress" />'))
        ->toContain('bg-accent-deep')
        ->toContain('x-bind:style="\'width:\' + (progress) + \'%\'"')
        ->toContain('x-bind:aria-valuenow="Math.round(progress)"')
        ->not->toContain('x-percent');
});

it('renders a quota bar through the progress bar, coloured by how much is spent', function () {
    expect(Blade::render('<x-quota-bar :used="50" :max="100" class="mt-1" />'))
        ->toContain('role="progressbar"')
        ->toContain('bg-accent-deep')
        ->toContain('mt-1');

    expect(Blade::render('<x-quota-bar :used="80" :max="100" />'))->toContain('bg-warn');
    expect(Blade::render('<x-quota-bar :used="95" :max="100" />'))->toContain('bg-danger');
    expect(Blade::render('<x-quota-bar :used="5" :max="0" />'))->toContain('style="width: 0%"');
});

it('renders a fraction with the whole quieter than the count', function () {
    expect(Blade::render('<x-fraction :value="3" :of="10" unit="GB" class="font-mono" />'))
        ->toContain('<span class="tabular-nums font-mono">3 <span class="text-fg-faint">/ 10 GB</span></span>');
});

it('renders keycaps for one key or several', function () {
    expect(Blade::render('<x-kbd keys="/" aria-hidden="true" />'))
        ->toContain('<kbd')
        ->toContain('font-sans text-xs text-fg-dim')
        ->toContain('aria-hidden="true"')
        ->toContain('>/</kbd>');

    expect(substr_count(Blade::render('<x-kbd :keys="[\'Ctrl\', \'K\']" />'), '<kbd'))->toBe(2);
});

it('renders a chevron turned over by its Alpine expression', function () {
    expect(Blade::render('<x-chevron open="isOpen(1)" class="text-fg-faint" />'))
        ->toContain('<svg')
        ->toContain('size-3.5')
        ->toContain('transition-transform duration-200')
        ->toContain('text-fg-faint')
        ->toContain('x-bind:class="(isOpen(1)) && \'-rotate-180\'"');
});

it('renders a rating in its band colour, and nothing without one', function () {
    expect(Blade::render('<x-rating-badge :rating="85" size="md" title="Score" />'))
        ->toContain('background-color: var(--color-accent)')
        ->toContain('px-2 py-0.5 text-sm')
        ->toContain('title="Score"')
        ->toContain('>85</span>');

    expect(Blade::render('<x-rating-badge :rating="30" />'))->toContain('var(--color-danger)');
    expect(trim(Blade::render('<x-rating-badge :rating="null" />')))->toBe('');
});

it('renders achievement progress as a bar and its counts', function () {
    $html = Blade::render('<x-achievement-progress :unlocked="5" :possible="20" hardcore class="w-32" />');

    expect($html)
        ->toContain('w-32')
        ->toContain('aria-valuenow="25"')
        ->toContain('title="Hardcore achievements"')
        ->toContain('5 <span class="text-fg-faint">/ 20</span>');

    expect(Blade::render('<x-achievement-progress :unlocked="0" :possible="0" />'))
        ->toContain('title="Achievements"')
        ->toContain('style="width: 0%"');
});

it('renders a decrypt state on the badge in the enum\'s tone', function () {
    expect(Blade::render('<x-decrypt.state :state="$state" />', ['state' => DecryptState::NeedsKey]))
        ->toContain('border-warn/50 text-warn')
        ->toContain(e(DecryptState::NeedsKey->hint()))
        ->toContain(DecryptState::NeedsKey->label());

    expect(Blade::render('<x-decrypt.state :state="$state" />', ['state' => DecryptState::Unchecked]))
        ->toContain('border-dashed')
        ->toContain('text-fg-faint');

    expect(Blade::render('<x-decrypt.state :state="$state" />', ['state' => DecryptState::Decrypting]))
        ->toContain('animate-pulse');
});

it('renders a file card badge as the small badge on the sunken ground', function () {
    expect(Blade::render('<x-game-file.badge tip="Format" wire:key="f">ISO</x-game-file.badge>'))
        ->toContain('h-5.5')
        ->toContain('bg-sunken')
        ->toContain('text-fg-muted')
        ->toContain('wire:key="f"')
        ->toContain('Format');

    expect(Blade::render('<x-game-file.badge tip="Flag" warn archived>Beta</x-game-file.badge>'))
        ->toContain('border-warn/50 text-warn')
        ->toContain('opacity-70')
        ->not->toContain('bg-sunken');

    expect(Blade::render('<x-game-file.badge tip="Role" archived>Game</x-game-file.badge>'))->toContain('text-fg-faint');
});

it('gives each decrypt state and conversion status a badge tone', function () {
    expect(DecryptState::NeedsKey->tone())->toBe('warn')
        ->and(DecryptState::Ready->tone())->toBe('accent')
        ->and(DecryptState::Decrypting->tone())->toBe('accent')
        ->and(DecryptState::Decrypted->tone())->toBe('muted')
        ->and(DecryptState::Unchecked->tone())->toBe('muted')
        ->and(DecryptState::Unchecked->dashed())->toBeTrue()
        ->and(DecryptState::Ready->dashed())->toBeFalse();

    expect(ConversionStatus::Queued->tone())->toBe('neutral')
        ->and(ConversionStatus::Running->tone())->toBe('accent')
        ->and(ConversionStatus::Verifying->tone())->toBe('accent')
        ->and(ConversionStatus::Swapping->tone())->toBe('accent')
        ->and(ConversionStatus::Done->tone())->toBe('success')
        ->and(ConversionStatus::Failed->tone())->toBe('danger')
        ->and(ConversionStatus::Cancelled->tone())->toBe('neutral');
});
