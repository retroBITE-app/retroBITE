<?php

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * A fresh install has no account and no default one: whoever reaches it first
 * makes the account, then picks how it looks, then gives it a ScreenScraper
 * account and, if they want one, a RetroAchievements one. EnsureOnboarded
 * keeps anybody from landing anywhere else until all of it is done.
 *
 * Pest.php marks every test's install as set up, so each test here starts by
 * putting it back.
 */
beforeEach(function () {
    AppSetting::put(AppSetting::IS_ONBOARDED, false);
});

it('sends every page to the account step while there is no account', function (string $path) {
    $this->get($path)->assertRedirect(route('onboarding.account'));
})->with(['/', '/dashboard', '/settings/interface']);

it('creates a verified first account with the user:create defaults and signs it in', function () {
    $this->get(route('onboarding.account'))->assertOk()->assertSee('Create account');

    Livewire::test('onboarding.account')
        ->set('username', 'retrogamer')
        ->set('password', 'correct-horse-battery')
        ->set('password_confirmation', 'correct-horse-battery')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect(route('onboarding.step', 'interface'));

    $user = User::query()->sole();

    expect($user->username)->toBe('retrogamer')
        ->and($user->name)->toBe('retrogamer')
        ->and($user->email)->toBe('retrogamer@retrobite.local')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Auth::id())->toBe($user->id);
});

it('refuses an account it should not make', function (array $input, string $field) {
    Livewire::test('onboarding.account')
        ->set($input)
        ->call('create')
        ->assertHasErrors($field);

    expect(User::query()->exists())->toBeFalse();
})->with([
    'no username' => [['username' => '', 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery'], 'username'],
    'passwords differ' => [['username' => 'retrogamer', 'password' => 'correct-horse-battery', 'password_confirmation' => 'something-else'], 'password'],
    'no password' => [['username' => 'retrogamer', 'password' => '', 'password_confirmation' => ''], 'password'],
]);

it('closes the account step once anybody can sign in', function () {
    // A form already on screen when somebody else made the first account.
    $component = Livewire::test('onboarding.account');

    User::factory()->create();

    $component
        ->set('username', 'latecomer')
        ->set('password', 'correct-horse-battery')
        ->set('password_confirmation', 'correct-horse-battery')
        ->call('create')
        ->assertRedirect(route('login'));

    expect(User::query()->count())->toBe(1)
        ->and(Auth::check())->toBeFalse();

    // And a fresh visit is turned away before it draws the form.
    $this->get(route('onboarding.account'))->assertRedirect(route('login'));
});

it('keeps a signed-in user inside the interface step until setup is finished', function (string $path) {
    $this->actingAs(User::factory()->create());

    $this->get($path)->assertRedirect(route('onboarding.step', 'interface'));
})->with(['/dashboard', '/settings/interface', '/consoles']);

it('lets a guest sign in mid-setup rather than trapping them', function () {
    User::factory()->create();

    $this->get(route('login'))->assertOk();
});

it('stores the interface choices and moves on to ScreenScraper', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('onboarding.step', 'interface'))->assertOk()->assertSee('Color scheme')->assertSee('Continue');

    Livewire::test('settings.interface', ['onboarding' => true])
        ->set('colorScheme', 'phosphor')
        ->set('scanlines', false)
        ->set('heroTitle', 'logo')
        ->set('uploads', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('onboarding.step', 'scraping'));

    expect(AppSetting::get(AppSetting::UI_COLOR_SCHEME))->toBe('phosphor')
        ->and(AppSetting::enabled(AppSetting::UI_SCANLINES))->toBeFalse()
        ->and(AppSetting::get(AppSetting::UI_HERO_TITLE))->toBe('logo')
        ->and(AppSetting::enabled(AppSetting::UI_UPLOADS))->toBeTrue()
        ->and(AppSetting::enabled(AppSetting::IS_ONBOARDED))->toBeFalse();
});

it('refuses an interface choice it does not know', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('settings.interface', ['onboarding' => true])
        ->set('colorScheme', 'neon')
        ->call('save')
        ->assertHasErrors('colorScheme');
});

it('stores the ScreenScraper account and moves on to RetroAchievements', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('onboarding.step', 'scraping'))->assertOk()->assertSee('Create a ScreenScraper account');

    Livewire::test('settings.screenscraper', ['onboarding' => true])
        ->set('username', 'retrogamer')
        ->set('password', 'hunter2')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('onboarding.step', 'achievements'));

    expect(AppSetting::get(AppSetting::SS_USER))->toBe('retrogamer')
        ->and(AppSetting::getSecret(AppSetting::SS_PASSWORD))->toBe('hunter2')
        ->and(AppSetting::enabled(AppSetting::IS_ONBOARDED))->toBeFalse();
});

it('will not go past ScreenScraper without an account', function (array $input, string $field) {
    $this->actingAs(User::factory()->create());

    Livewire::test('settings.screenscraper', ['onboarding' => true])
        ->set($input)
        ->call('save')
        ->assertHasErrors($field)
        ->assertNoRedirect();
})->with([
    'no account name' => [['username' => '', 'password' => 'hunter2'], 'username'],
    'no password' => [['username' => 'retrogamer', 'password' => ''], 'password'],
    'an email for a name' => [['username' => 'me@example.com', 'password' => 'hunter2'], 'username'],
]);

it('takes a stored ScreenScraper password as given when the step is revisited', function () {
    $this->actingAs(User::factory()->create());

    AppSetting::put(AppSetting::SS_USER, 'retrogamer');
    AppSetting::putSecret(AppSetting::SS_PASSWORD, 'hunter2');

    Livewire::test('settings.screenscraper', ['onboarding' => true])
        ->assertSet('username', 'retrogamer')
        ->assertSet('password', '')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('onboarding.step', 'achievements'));

    expect(AppSetting::getSecret(AppSetting::SS_PASSWORD))->toBe('hunter2');
});

it('stores the RetroAchievements account and finishes setup', function () {
    Http::fake(['*API_GetUserProfile*' => Http::response(['User' => 'tester'], 200)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('onboarding.step', 'achievements'))->assertOk()->assertSee('Skip for now');

    Livewire::test('settings.retroachievements', ['onboarding' => true])
        ->set('username', 'tester')
        ->set('apiKey', 'abcdefghijklmnopqrstuvwxyz123456')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->retroachievements_username)->toBe('tester')
        ->and(AppSetting::getSecret(AppSetting::RA_API_KEY))->toBe('abcdefghijklmnopqrstuvwxyz123456')
        ->and(AppSetting::enabled(AppSetting::IS_ONBOARDED))->toBeTrue();

    $this->get('/dashboard')->assertOk();
});

it('does not finish on a RetroAchievements name nobody holds', function () {
    Http::fake(['*API_GetUserProfile*' => Http::response([], 404)]);

    $this->actingAs(User::factory()->create());

    Livewire::test('settings.retroachievements', ['onboarding' => true])
        ->set('username', 'nobody')
        ->set('apiKey', 'abcdefghijklmnopqrstuvwxyz123456')
        ->call('save')
        ->assertHasErrors('username')
        ->assertNoRedirect();

    expect(AppSetting::enabled(AppSetting::IS_ONBOARDED))->toBeFalse();
});

it('finishes setup without RetroAchievements when it is skipped', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('settings.retroachievements', ['onboarding' => true])
        ->set('username', 'halftyped')
        ->call('finishOnboarding')
        ->assertRedirect(route('dashboard'));

    expect(AppSetting::enabled(AppSetting::IS_ONBOARDED))->toBeTrue()
        ->and($user->fresh()->retroachievements_username)->toBeNull()
        ->and(AppSetting::getSecret(AppSetting::RA_API_KEY))->toBeNull();
});

it('never shows the wizard to an install that is set up', function () {
    AppSetting::put(AppSetting::IS_ONBOARDED, true);

    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')->assertOk();

    foreach (['interface', 'scraping', 'achievements'] as $step) {
        $this->get(route('onboarding.step', $step))->assertRedirect(route('dashboard'));
    }

    $this->get(route('onboarding.account'))->assertRedirect(route('dashboard'));
});

it('marks an install that already has accounts as set up, and leaves an empty one alone', function () {
    $migration = require database_path('migrations/2026_09_25_100000_mark_existing_installs_onboarded.php');

    AppSetting::query()->where('key', AppSetting::IS_ONBOARDED)->delete();
    AppSetting::flush();

    $migration->up();

    expect(AppSetting::query()->where('key', AppSetting::IS_ONBOARDED)->exists())->toBeFalse();

    User::factory()->create();
    $migration->up();
    AppSetting::flush();

    expect(AppSetting::enabled(AppSetting::IS_ONBOARDED))->toBeTrue();
});

it('embeds the settings screens without their tabs or the cards a new install has nothing for', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('onboarding.step', 'scraping'))
        ->assertOk()
        ->assertSee('Create a ScreenScraper account')
        ->assertDontSee('Check now')
        ->assertDontSee(route('interface.edit'), false);

    $this->get(route('onboarding.step', 'achievements'))
        ->assertOk()
        ->assertSee('Find your API key')
        ->assertDontSee('Hash index');

    // The screens themselves are unchanged in Settings.
    AppSetting::put(AppSetting::IS_ONBOARDED, true);

    $this->get(route('screenscraper.edit'))->assertOk()->assertSee('Check now')->assertSee(route('interface.edit'), false);
});

it('lays the shipped scene behind onboarding while nothing is scraped', function () {
    $this->get(route('onboarding.account'))
        ->assertOk()
        ->assertSee(asset('images/default-backdrop.jpg'), false);
});
