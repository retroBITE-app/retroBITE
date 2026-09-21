<?php

use App\Models\User;
use App\Support\SystemActivity;
use Livewire\Livewire;

beforeEach(function () {
    SystemActivity::forget();
    $this->actingAs(User::factory()->create());
});

it('says the system is idle, and polls slowly, when nothing is queued', function () {
    Livewire::test('system-activity')
        ->assertSee('idle')
        // assertSeeHtml, not assertSee: the attribute would be escaped.
        ->assertSeeHtml('wire:poll.15s');
});

it('names the work and polls quickly while there is any', function () {
    queueRow('default');

    Livewire::test('system-activity')
        ->assertSee('Scanning')
        ->assertDontSee('idle')
        ->assertSeeHtml('wire:poll.3s');
});

it('rides along on every page', function () {
    // The assertion that catches the layout wiring being wrong. Nothing else
    // in the suite would notice the component being dropped from the sidebar.
    $this->get(route('dashboard'))->assertOk()->assertSee('Activity');
});

it('reads the same figures on the consoles page as in the sidebar', function () {
    // The hand-written count this replaced knew three queue names and ignored
    // this one, so a library busy with achievements looked idle here.
    queueRow('ra');

    Livewire::test('consoles.index')->assertSee('Achievements');
});
