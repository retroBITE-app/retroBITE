<?php

use App\Jobs\RetroAchievements\ReconcileProgress;
use App\Jobs\RetroAchievements\SyncHashIndex;
use App\Jobs\RetroAchievements\SyncRecentUnlocks;
use App\Models\AppSetting;
use App\Models\Game;
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
        ->assertSet('hasKey', true)
        ->call('forgetKey')
        ->assertSet('hasKey', false);

    expect(AppSetting::getSecret(AppSetting::RA_API_KEY))->toBeNull();
});

it('offers to remove a key only once there is one', function () {
    // The settings table is the only place a key lives now. There is nothing
    // to remove until somebody has typed one, and a button that clears nothing
    // would be a lie.
    Livewire::test('settings.retroachievements')
        ->assertSet('hasKey', false)
        ->assertDontSee('Remove stored key');

    AppSetting::putSecret(AppSetting::RA_API_KEY, 'typed-key');

    Livewire::test('settings.retroachievements')
        ->assertSet('hasKey', true)
        ->assertSee('Remove stored key')
        ->call('forgetKey')
        ->assertSet('hasKey', false);
});

it('says where the API key is found', function () {
    // The key is not on the profile and not on the front of Settings, and
    // hunting for it is the step people write in to ask about.
    Livewire::test('settings.retroachievements')
        ->assertSee('https://retroachievements.org/settings?tab=applications', escape: false)
        ->assertSee('Find your API key');
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

it('queues the nightly index download on demand, one per console in the library', function () {
    Queue::fake();

    AppSetting::putSecret(AppSetting::RA_API_KEY, 'abcdefghijklmnopqrstuvwxyz123456');
    Game::factory()->forConsole('psx')->create();

    $this->get(route('retroachievements.edit'))->assertSee('Refresh indexes now');

    Livewire::test('settings.retroachievements')->call('syncIndex');

    Queue::assertPushed(SyncHashIndex::class, 1);
});

it('refuses to refresh the indexes without a key', function () {
    Queue::fake();

    Game::factory()->forConsole('psx')->create();

    Livewire::test('settings.retroachievements')->call('syncIndex');

    Queue::assertNotPushed(SyncHashIndex::class);
});
