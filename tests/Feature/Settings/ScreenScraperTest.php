<?php

use App\Models\AppSetting;
use App\Models\User;
use App\Services\ScreenScraperService;
use App\Support\ScreenScraperCredentials;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('screenscraper.min_interval', 0);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('stores the account, keeping the password encrypted', function () {
    Livewire::test('settings.screenscraper')
        ->set('username', 'somebody')
        ->set('password', 'secret')
        ->call('save')
        ->assertHasNoErrors()
        // Cleared from the component, so the stored one is never sent back to
        // the browser on the next render.
        ->assertSet('password', '');

    expect(AppSetting::get(AppSetting::SS_USER))->toBe('somebody')
        ->and(AppSetting::getSecret(AppSetting::SS_PASSWORD))->toBe('secret')
        // The name is readable; the password is not.
        ->and(AppSetting::get(AppSetting::SS_PASSWORD))->not->toBe('secret');
});

it('keeps the stored password when the field is left blank', function () {
    AppSetting::putSecret(AppSetting::SS_PASSWORD, 'secret');

    // The field never shows it, so an empty submission has to mean "leave it
    // alone" rather than "clear it".
    Livewire::test('settings.screenscraper')
        ->set('username', 'somebody')
        ->call('save');

    expect(AppSetting::getSecret(AppSetting::SS_PASSWORD))->toBe('secret');
});

it('clears the password only when asked', function () {
    AppSetting::put(AppSetting::SS_USER, 'somebody');
    AppSetting::putSecret(AppSetting::SS_PASSWORD, 'secret');

    Livewire::test('settings.screenscraper')
        ->assertSet('hasPassword', true)
        ->call('forgetPassword')
        ->assertSet('hasPassword', false);

    // The name stays: it is not the secret, and losing it would make the next
    // lookup answer as somebody else without saying so.
    expect(AppSetting::getSecret(AppSetting::SS_PASSWORD))->toBeNull()
        ->and(AppSetting::get(AppSetting::SS_USER))->toBe('somebody');
});

it('refuses an email address in the account field', function () {
    // The mistake worth catching: ScreenScraper accepts it and then answers on
    // the developer account, which looks exactly like a working login.
    Livewire::test('settings.screenscraper')
        ->set('username', 'someone@example.com')
        ->call('save')
        ->assertHasErrors('username');

    expect(AppSetting::get(AppSetting::SS_USER))->toBeNull();
});

it('reads the account the service sends from settings, not the environment', function () {
    AppSetting::put(AppSetting::SS_USER, 'somebody');
    AppSetting::putSecret(AppSetting::SS_PASSWORD, 'secret');

    Http::fake(['*' => Http::response(json_encode(['response' => ['ssuser' => ['id' => 'somebody']]]), 200)]);

    app(ScreenScraperService::class)->account();

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['ssid'] ?? null) === 'somebody'
            && ($query['sspassword'] ?? null) === 'secret';
    });

    expect(ScreenScraperCredentials::configured())->toBeTrue();
});

it('says the login was not accepted when the provider answers as somebody else', function () {
    AppSetting::put(AppSetting::SS_USER, 'somebody');
    AppSetting::putSecret(AppSetting::SS_PASSWORD, 'wrong');

    // ScreenScraper never refuses a login it does not recognise — it answers
    // on the developer account instead, and nothing about a game lookup says
    // which of the two replied. This is the only place the difference shows.
    Http::fake(['*' => Http::response(json_encode(['response' => ['ssuser' => ['id' => 'retrobite-dev']]]), 200)]);

    // The variant, not just that a toast appeared: success and failure both
    // toast, and only it tells them apart.
    Livewire::test('settings.screenscraper')
        ->call('check')
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => $params['dataset']['variant'] === 'warning');
});

it('confirms a login the provider answers as', function () {
    AppSetting::put(AppSetting::SS_USER, 'somebody');
    AppSetting::putSecret(AppSetting::SS_PASSWORD, 'secret');

    Http::fake(['*' => Http::response(json_encode(['response' => ['ssuser' => [
        'id' => 'somebody', 'requeststoday' => '120', 'maxrequestsperday' => '20000',
    ]]]), 200)]);

    Livewire::test('settings.screenscraper')
        ->call('check')
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => $params['dataset']['variant'] === 'success');
});

it('renders the settings screen', function () {
    $this->get(route('screenscraper.edit'))->assertOk();
});
