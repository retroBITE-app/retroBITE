<?php

use App\Models\User;
use App\Support\SystemActivity;
use Livewire\Livewire;

beforeEach(function () {
    SystemActivity::forget();
    $this->actingAs(User::factory()->create());
});

it('says the system is idle when nothing is queued', function () {
    Livewire::test('system-activity')
        ->assertOk()
        ->assertViewHas('activity', fn (SystemActivity $activity): bool => ! $activity->busy());
});

it('adds every queue into the one figure on the heading row', function () {
    queueRow('default');
    queueRow('ra');

    Livewire::test('system-activity')
        ->assertViewHas('activity', fn (SystemActivity $activity): bool => $activity->total()->remaining() === 2);
});

it('names every queue, including the ones with nothing in them', function () {
    queueRow('default');

    // The fold is also the only place the interface says what kinds of work
    // exist, so a quiet queue keeps its row rather than disappearing from the
    // list the moment it drains. Thumbnails gets a row of its own.
    $labels = array_map(fn ($queue) => $queue->label, SystemActivity::current()->all());

    expect($labels)->toBe([
        'Scanning', 'Identifying', 'Hashing', 'Artwork', 'Thumbnails', 'Achievements', 'Progress', 'Toolbox',
        'Toolbox - Conversion',
    ]);
});

it('rides along on every page', function () {
    // The assertion that catches the layout wiring being wrong. Nothing else
    // in the suite would notice the component being dropped from the sidebar.
    $this->get(route('dashboard'))->assertOk()->assertSeeLivewire('system-activity');
});
