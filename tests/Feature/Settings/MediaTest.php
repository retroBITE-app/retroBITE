<?php

use App\Models\AppSetting;
use App\Models\User;
use App\Support\MediaTypes;
use App\Transfers\BatoceraTarget;
use Livewire\Livewire;

/*
 * Settings → Media: every type offered says what it is, and what it becomes
 * on a Batocera box.
 */

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('describes every type it offers', function () {
    $undescribed = array_values(array_filter(MediaTypes::offered(), fn (string $type): bool => MediaTypes::describe($type) === null));

    expect($undescribed)->toBe([]);
});

it('maps the types a transfer sends to Batocera\'s names for them', function () {
    expect(BatoceraTarget::artworkLabels())
        ->toMatchArray([
            'box-2D' => 'boxart',
            'ss' => 'image',
            'mixrbv2' => 'mix',
            'sstitle' => 'image, title shot',
            'wheel' => 'logo',
            'manuel' => 'manual',
        ])
        ->not->toHaveKey('video');
});

it('switches on what Batocera shows, one type per tag, without saving', function () {
    AppSetting::put(AppSetting::MEDIA_TYPES, ['video', 'box-3D']);

    $component = Livewire::test('settings.media')->call('useBatocera');

    $on = array_keys(array_filter($component->get('enabled')));
    sort($on);

    expect($on)->toBe(['box-2D', 'box-2D-back', 'fanart', 'manuel', 'mixrbv2', 'ss', 'sstitle', 'support-2D', 'wheel'])
        // The fallbacks stay off: Batocera would only show them if the first choice were missing.
        ->and($on)->not->toContain('box-3D')
        // Chosen, not kept, until Save.
        ->and(MediaTypes::enabled())->toBe(['video', 'box-3D']);
});
