<?php

use App\Models\User;
use App\Support\SystemActivity;
use Livewire\Livewire;

beforeEach(function () {
    SystemActivity::forget();
    $this->actingAs(User::factory()->create());
});

it('says the system is idle when nothing is queued, and does not poll', function () {
    // It waits for the queues' own signal instead of asking on a timer.
    Livewire::test('system-activity')
        ->assertSee('idle')
        ->assertDontSeeHtml('wire:poll')
        ->assertSeeHtml("live.system('activity'");
});

it('names the work while there is any', function () {
    queueRow('default');

    Livewire::test('system-activity')
        ->assertSee('Scanning')
        ->assertDontSee('idle');
});

it('draws every queue as one bar, and each on its own only once unfolded', function () {
    queueRow('default');
    queueRow('ra');

    Livewire::test('system-activity')
        // The one figure on the heading row is the whole of it …
        ->assertSee('2')
        // … and the fold is the viewer's, remembered in their own browser.
        ->assertSeeHtml('x-show="open"')
        ->assertSeeHtml('sidebar.activity')
        ->assertSee('Scanning')
        ->assertSee('Achievements');
});

it('names every queue once unfolded, including the ones with nothing in them', function () {
    queueRow('default');

    // The fold is also the only place the interface says what kinds of work
    // exist, so a quiet queue keeps its row rather than disappearing from the
    // list the moment it drains.
    Livewire::test('system-activity')
        ->assertSee('Scanning')
        ->assertSee('Identifying')
        ->assertSee('Hashing')
        ->assertSee('Artwork')
        ->assertSee('Achievements')
        ->assertSee('Progress');
});

it('keeps the fold on an idle system rather than hiding the control', function () {
    Livewire::test('system-activity')
        ->assertSee('idle')
        ->assertSeeHtml('x-show="open"')
        ->assertSee('Achievements');
});

it('rides along on every page', function () {
    // The assertion that catches the layout wiring being wrong. Nothing else
    // in the suite would notice the component being dropped from the sidebar.
    $this->get(route('dashboard'))->assertOk()->assertSee('Activity');
});

it('gives thumbnails a row of their own', function () {
    queueRow('thumbnails');

    Livewire::test('system-activity')->assertSee('Thumbnails');
});
