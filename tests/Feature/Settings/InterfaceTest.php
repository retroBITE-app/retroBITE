<?php

use App\Enums\ColorScheme;
use App\Enums\ConsoleGrouping;
use App\Enums\ConsoleSort;
use App\Models\AppSetting;
use App\Models\Game;
use App\Models\Media;
use App\Models\User;
use Livewire\Livewire;

it('saves the UI settings', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('interface.edit'))->assertOk();

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
});

it('saves the color scheme and tells the page', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('settings.interface')
        ->assertSet('colorScheme', 'default')
        ->set('colorScheme', 'cobalt')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('color-scheme-saved', scheme: 'cobalt');

    expect(AppSetting::get(AppSetting::UI_COLOR_SCHEME))->toBe('cobalt');
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

it('saves how the consoles are ordered and grouped', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('settings.interface')
        ->assertSet('consoleSort', 'name')
        ->assertSet('consoleGrouping', 'none')
        ->set('consoleSort', 'games')
        ->set('consoleGrouping', 'decade')
        ->call('save')
        ->assertHasNoErrors();

    expect(ConsoleSort::current())->toBe(ConsoleSort::Games)
        ->and(ConsoleGrouping::current())->toBe(ConsoleGrouping::Decade);
});

it('refuses an order or a grouping it does not know', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('settings.interface')
        ->set('consoleSort', 'colour')
        ->set('consoleGrouping', 'mood')
        ->call('save')
        ->assertHasErrors(['consoleSort', 'consoleGrouping']);

    expect(AppSetting::get(AppSetting::UI_CONSOLE_SORT))->toBe('name')
        ->and(AppSetting::get(AppSetting::UI_CONSOLE_GROUPING))->toBe('none');
});

it('falls back to A to Z, ungrouped, for a stored choice that no longer exists', function () {
    AppSetting::put(AppSetting::UI_CONSOLE_SORT, 'retired');
    AppSetting::put(AppSetting::UI_CONSOLE_GROUPING, 'retired');

    expect(ConsoleSort::current())->toBe(ConsoleSort::Name)
        ->and(ConsoleGrouping::current())->toBe(ConsoleGrouping::None);
});
