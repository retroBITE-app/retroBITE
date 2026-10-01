<?php

use App\Models\AppSetting;
use App\Models\User;
use App\Support\MediaTypes;
use Livewire\Livewire;

/*
 * Settings → Media: every type offered says what it is, and what it becomes
 * when a game is sent laid out for a transfer target.
 */

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('describes every type it offers', function () {
    $undescribed = array_values(array_filter(MediaTypes::offered(), fn (string $type): bool => MediaTypes::describe($type) === null));

    expect($undescribed)->toBe([]);
});

it('maps the types a transfer sends to the chosen target\'s names for them', function () {
    $component = Livewire::test('settings.media')->assertSet('target', 'batocera');

    expect($component->instance()->targetSlots)->toMatchArray([
        'box-2D' => 'boxart',
        'ss' => 'image',
        'mixrbv2' => 'mix',
        'sstitle' => 'image, title shot',
        'wheel' => 'logo',
        'manuel' => 'manual',
        'video' => 'video',
    ]);

    $component->set('target', 'daijishou');

    expect($component->instance()->targetSlots)->toBe(['box-2D' => 'Box Art', 'box-3D' => 'Box Art', 'sstitle' => 'Title', 'ss' => 'Screenshot']);
});

it('switches on what Batocera shows, one type per tag, without saving', function () {
    AppSetting::put(AppSetting::MEDIA_TYPES, ['video', 'box-3D']);

    $component = Livewire::test('settings.media')->call('useRecommended', 'batocera');

    $on = array_keys(array_filter($component->get('enabled')));
    sort($on);

    expect($on)->toBe(['box-2D', 'box-2D-back', 'fanart', 'manuel', 'mixrbv2', 'ss', 'sstitle', 'support-2D', 'wheel'])
        // The fallbacks stay off: Batocera would only show them if the first choice were missing.
        ->and($on)->not->toContain('box-3D')
        // Chosen, not kept, until Save.
        ->and(MediaTypes::enabled())->toBe(['video', 'box-3D']);
});

it('recommends for every target what it shows, and never a video', function (string $target, array $expected) {
    $component = Livewire::test('settings.media')->call('useRecommended', $target);

    $on = array_keys(array_filter($component->get('enabled')));
    sort($on);

    expect($on)->toBe($expected)->and($component->get('target'))->toBe($target);
})->with([
    'Recalbox' => ['recalbox', ['mixrbv2', 'ss', 'wheel']],
    'RetroPie' => ['retropie', ['box-2D', 'ss', 'wheel']],
    'ES-DE' => ['es-de', ['box-2D', 'box-2D-back', 'box-3D', 'fanart', 'manuel', 'mixrbv2', 'ss', 'sstitle', 'support-2D', 'wheel']],
    'Daijishō' => ['daijishou', ['box-2D', 'ss', 'sstitle']],
    'Open PS2 Loader' => ['opl', ['box-2D', 'ss', 'sstitle', 'support-2D']],
]);
