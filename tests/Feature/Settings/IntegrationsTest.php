<?php

use App\Jobs\RetroAchievements\ReconcileProgress;
use App\Jobs\RetroAchievements\SyncRecentUnlocks;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('renders', function () {
    $this->get(route('integrations.edit'))->assertOk()->assertSee('RetroAchievements');
});

it('saves the username against the person and the key encrypted', function () {
    Livewire::test('settings.integrations')
        ->set('username', 'tester')
        ->set('apiKey', 'secret-key')
        ->call('save');

    expect($this->user->refresh()->retroachievements_username)->toBe('tester')
        ->and(AppSetting::getSecret(AppSetting::RA_API_KEY))->toBe('secret-key');

    // Encrypted at rest, so it is not sitting in plain text in a database dump.
    expect(AppSetting::get(AppSetting::RA_API_KEY))->not->toBe('secret-key');
});

it('keeps the stored key when the field is left blank', function () {
    AppSetting::putSecret(AppSetting::RA_API_KEY, 'secret-key');

    // The field never shows the stored key, so an empty submission has to mean
    // "leave it alone" rather than "clear it".
    Livewire::test('settings.integrations')
        ->set('username', 'tester')
        ->call('save');

    expect(AppSetting::getSecret(AppSetting::RA_API_KEY))->toBe('secret-key');
});

it('clears the key only when asked', function () {
    AppSetting::putSecret(AppSetting::RA_API_KEY, 'secret-key');

    Livewire::test('settings.integrations')->call('forgetKey');

    expect(AppSetting::getSecret(AppSetting::RA_API_KEY))->toBeNull();
});

it('queues a sync rather than running one in the request', function () {
    Queue::fake();

    $this->user->forceFill(['retroachievements_username' => 'tester'])->save();

    // Http::preventStrayRequests() is on, so a synchronous call here would
    // fail the test rather than quietly work.
    Livewire::test('settings.integrations')->call('syncProgress', false);
    Livewire::test('settings.integrations')->call('syncProgress', true);

    Queue::assertPushed(SyncRecentUnlocks::class);
    Queue::assertPushed(ReconcileProgress::class);
});

it('refuses to sync without a username', function () {
    Queue::fake();

    Livewire::test('settings.integrations')->call('syncProgress', false);

    Queue::assertNothingPushed();
});
