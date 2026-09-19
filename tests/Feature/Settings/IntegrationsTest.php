<?php

use App\Jobs\RetroAchievements\ReconcileProgress;
use App\Jobs\RetroAchievements\SyncRecentUnlocks;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    // A key has to be configured for the existence check to be asked at all —
    // without one it returns "cannot say" and the form saves regardless.
    config()->set('retroachievements.api_key_fallback', 'test-key');
    config()->set('retroachievements.min_interval', 0);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('renders', function () {
    $this->get(route('integrations.edit'))->assertOk()->assertSee('RetroAchievements');
});

it('saves the username against the person and the key encrypted', function () {
    Http::fake(['*API_GetUserProfile*' => Http::response(['User' => 'tester'], 200)]);

    Livewire::test('settings.integrations')
        ->set('username', 'tester')
        ->set('apiKey', 'secret-key')
        ->call('save');

    expect($this->user->refresh()->retroachievements_username)->toBe('tester')
        ->and(AppSetting::getSecret(AppSetting::RA_API_KEY))->toBe('secret-key');

    // Encrypted at rest, so it is not sitting in plain text in a database dump.
    expect(AppSetting::get(AppSetting::RA_API_KEY))->not->toBe('secret-key');
});

it('refuses an API key typed into the username field', function () {
    // Done twice by hand before the form objected. A RetroAchievements
    // username is 2 to 20 characters of letters and numbers — its own API says
    // so — and a key is 32, so the rule that catches this is theirs, not ours.
    Livewire::test('settings.integrations')
        ->set('username', '3i804ZtPakGzLKTKh4f8P1BnhOqjf7PQ')
        ->call('save')
        ->assertHasErrors('username');

    expect($this->user->refresh()->retroachievements_username)->toBeNull();
});

it('refuses a name RetroAchievements does not know', function () {
    // 404 with an empty body is how they answer for a well-formed name nobody
    // holds. Saving it would leave progress silently failing every quarter of
    // an hour with nothing on screen to say why.
    Http::fake(['*API_GetUserProfile*' => Http::response([], 404)]);

    Livewire::test('settings.integrations')
        ->set('username', 'Zzqqxxnotreal')
        ->call('save')
        ->assertHasErrors('username');

    expect($this->user->refresh()->retroachievements_username)->toBeNull();
});

it('saves anyway when the account cannot be checked', function () {
    // No key configured, so there is no way to ask. Refusing on the strength
    // of that would be refusing because the network is down.
    config()->set('retroachievements.api_key_fallback', '');

    Livewire::test('settings.integrations')
        ->set('username', 'tester')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->user->refresh()->retroachievements_username)->toBe('tester');
});

it('keeps the stored key when the field is left blank', function () {
    AppSetting::putSecret(AppSetting::RA_API_KEY, 'secret-key');
    Http::fake(['*API_GetUserProfile*' => Http::response(['User' => 'tester'], 200)]);

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
