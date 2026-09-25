<?php

use App\Enums\ColorScheme;
use App\Models\AppSetting;
use App\Models\Game;
use App\Models\Media;
use App\Models\User;
use Livewire\Livewire;

/**
 * The CRT overlay is a stored setting rather than a deployed one, so the switch
 * in Settings → UI has to reach the pages that draw it. The sign-in backdrop
 * is the cheapest of the four to assert: it needs no login and no artwork.
 */
it('draws the scanline overlay only while it is switched on', function () {
    // An install with no account answers every page with onboarding.
    User::factory()->create();

    $this->get('/')->assertOk()->assertSee('scanlines', false);

    AppSetting::put(AppSetting::UI_SCANLINES, false);

    $this->get('/')->assertOk()->assertDontSee('scanlines', false);
});

it('saves the UI settings', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('interface.edit'))->assertOk()->assertSee('CRT scanlines')->assertSee('Game page title');

    Livewire::test('settings.interface')
        ->assertSet('scanlines', true)
        ->assertSet('heroTitle', 'text')
        ->set('scanlines', false)
        ->set('heroTitle', 'logo')
        ->call('save')
        ->assertHasNoErrors();

    expect(AppSetting::enabled(AppSetting::UI_SCANLINES))->toBeFalse()
        ->and(AppSetting::get(AppSetting::UI_HERO_TITLE))->toBe('logo');
});

it('keeps ROM uploads off until they are switched on', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('interface.edit'))->assertOk()->assertSee('ROM uploads');

    Livewire::test('settings.interface')
        ->assertSet('uploads', false)
        ->set('uploads', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(AppSetting::enabled(AppSetting::UI_UPLOADS))->toBeTrue();
});

it('refuses a hero title it does not know', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('settings.interface')
        ->set('heroTitle', 'both')
        ->call('save')
        ->assertHasErrors('heroTitle');

    expect(AppSetting::get(AppSetting::UI_HERO_TITLE))->toBe('text');
});

it('heads the game page with the logo or the title, never both', function () {
    $this->actingAs(User::factory()->create());

    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Final Fantasy IX', 'slug' => 'ff9']);
    Media::factory()->for($game)->ofType('wheel', 'eu')->create();

    // Text by default, even with a logo on hand.
    expect(Livewire::test('games.show', ['game' => $game])->instance()->showLogo)->toBeFalse();

    AppSetting::put(AppSetting::UI_HERO_TITLE, 'logo');

    expect(Livewire::test('games.show', ['game' => $game])->instance()->showLogo)->toBeTrue();
});

it('falls back to the title for a game with no logo', function () {
    $this->actingAs(User::factory()->create());

    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Final Fantasy IX', 'slug' => 'ff9']);

    AppSetting::put(AppSetting::UI_HERO_TITLE, 'logo');

    expect(Livewire::test('games.show', ['game' => $game])->instance()->showLogo)->toBeFalse();

    $this->get(route('games.show', $game->routeParameters()))->assertOk()->assertSee('Final Fantasy IX');
});

it('keeps the settings tab underlined after a save re-renders the page', function () {
    $this->actingAs(User::factory()->create());

    // Through the real update endpoint rather than Livewire::test(), which
    // renders off the page's route and so never underlines any tab.
    $page = $this->get(route('interface.edit'))->assertOk()->getContent();

    preg_match('~wire:snapshot="([^"]+)"[^>]*wire:name="settings\.interface"~', $page, $match);

    $response = $this->withHeader('X-Livewire', 'true')->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => html_entity_decode($match[1]),
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
        ]],
    ])->assertOk();

    expect($response->json('components.0.effects.html'))
        ->toMatch('~text-fg-bright shadow-underline"\s*>\s*UI\s*</a>~');
});

it('saves the color scheme and writes it onto every page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('interface.edit'))->assertOk()->assertSee('data-scheme="default"', false);

    Livewire::test('settings.interface')
        ->assertSet('colorScheme', 'default')
        ->set('colorScheme', 'cobalt')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('color-scheme-saved', scheme: 'cobalt');

    expect(AppSetting::get(AppSetting::UI_COLOR_SCHEME))->toBe('cobalt');

    $this->get(route('dashboard'))->assertOk()->assertSee('data-scheme="cobalt"', false);
});

it('wears the color scheme on the sign-in page too', function () {
    // An install with no account answers every page with onboarding.
    User::factory()->create();

    AppSetting::put(AppSetting::UI_COLOR_SCHEME, 'famicom');

    $this->get('/')
        ->assertOk()
        ->assertSee('data-scheme="famicom"', false)
        ->assertSee(route('favicon', 'famicom'), false);
});

it('refuses a color scheme it does not ship', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('settings.interface')
        ->set('colorScheme', 'neon')
        ->call('save')
        ->assertHasErrors('colorScheme');

    expect(AppSetting::get(AppSetting::UI_COLOR_SCHEME))->toBe('default');
});

it('falls back to the default for a stored scheme that no longer ships', function () {
    AppSetting::put(AppSetting::UI_COLOR_SCHEME, 'retired');

    expect(ColorScheme::current())->toBe(ColorScheme::Default);
});
