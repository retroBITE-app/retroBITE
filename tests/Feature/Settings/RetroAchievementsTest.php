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
    $this->get(route('retroachievements.edit'))->assertOk()->assertSee('RetroAchievements');
});

it('keeps the old settings URL working', function () {
    // The screen was called Integrations until it was plainly only ever about
    // one service. Somebody's bookmark should not pay for the rename.
    $this->get('/settings/integrations')->assertRedirect('/settings/retroachievements');
});

it('leads with hardcore until somebody says otherwise', function () {
    // On by default: it is the figure RetroAchievements itself leads with.
    expect(AppSetting::enabled(AppSetting::RA_HARDCORE_PRIMARY))->toBeTrue();

    Livewire::test('settings.retroachievements')
        ->assertSet('hardcorePrimary', true)
        ->set('hardcorePrimary', false)
        ->call('save');

    expect(AppSetting::enabled(AppSetting::RA_HARDCORE_PRIMARY))->toBeFalse();
});

it('saves the username against the person and the key encrypted', function () {
    Http::fake(['*API_GetUserProfile*' => Http::response(['User' => 'tester'], 200)]);

    Livewire::test('settings.retroachievements')
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
    Livewire::test('settings.retroachievements')
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

    Livewire::test('settings.retroachievements')
        ->set('username', 'Zzqqxxnotreal')
        ->call('save')
        ->assertHasErrors('username');

    expect($this->user->refresh()->retroachievements_username)->toBeNull();
});

it('saves anyway when the account cannot be checked', function () {
    // No key configured, so there is no way to ask. Refusing on the strength
    // of that would be refusing because the network is down.
    config()->set('retroachievements.api_key_fallback', '');

    Livewire::test('settings.retroachievements')
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
    Livewire::test('settings.retroachievements')
        ->set('username', 'tester')
        ->call('save');

    expect(AppSetting::getSecret(AppSetting::RA_API_KEY))->toBe('secret-key');
});

it('clears the key only when asked', function () {
    AppSetting::putSecret(AppSetting::RA_API_KEY, 'secret-key');

    Livewire::test('settings.retroachievements')
        ->assertSet('hasStoredKey', true)
        ->call('forgetKey')
        ->assertSet('hasStoredKey', false);

    expect(AppSetting::getSecret(AppSetting::RA_API_KEY))->toBeNull();
});

it('offers to remove only a key it stored itself', function () {
    // A key from the environment is whoever deployed the container's, and no
    // button in here can reach it. Offering one would be a lie: it would
    // clear nothing and the screen would still say a key is configured.
    config()->set('retroachievements.api_key_fallback', 'env-key');

    Livewire::test('settings.retroachievements')
        ->assertSet('hasKey', true)
        ->assertSet('hasStoredKey', false)
        ->assertDontSee('Remove stored key');

    AppSetting::putSecret(AppSetting::RA_API_KEY, 'typed-key');

    Livewire::test('settings.retroachievements')
        ->assertSet('hasStoredKey', true)
        ->assertSee('Remove stored key')
        // And removing it hands the API back to the one in the environment
        // rather than leaving the integration unlinked.
        ->call('forgetKey')
        ->assertSet('hasKey', true);
});

it('queues a sync rather than running one in the request', function () {
    Queue::fake();

    $this->user->forceFill(['retroachievements_username' => 'tester'])->save();

    // Http::preventStrayRequests() is on, so a synchronous call here would
    // fail the test rather than quietly work.
    Livewire::test('settings.retroachievements')->call('syncProgress', false);
    Livewire::test('settings.retroachievements')->call('syncProgress', true);

    Queue::assertPushed(SyncRecentUnlocks::class);
    Queue::assertPushed(ReconcileProgress::class);
});

it('refuses to sync without a username', function () {
    Queue::fake();

    Livewire::test('settings.retroachievements')->call('syncProgress', false);

    Queue::assertNothingPushed();
});
