<?php

use App\Models\AppSetting;
use App\Models\User;
use App\Resources\ConsoleResource;
use App\Support\Console;
use App\Support\ConsoleOverrides;
use Livewire\Livewire;

/**
 * The console files are shipped config and cannot be edited on a running install,
 * so a wrong ScreenScraper id or a missing extension used to need a rebuild. These
 * cover the merge that makes an override reach every reader, and the two mistakes
 * the previous build made: replacing a whole entry, and letting an override invent
 * a console.
 */
it('puts an override in force for every reader of the config', function () {
    ConsoleOverrides::remember('snes', ['name' => 'SNES', 'file_extensions' => 'smc, sfc']);

    expect(config('consoles.snes.name'))->toBe('SNES')
        ->and(Console::tryFrom('snes')?->name)->toBe('SNES')
        ->and(ConsoleResource::make('snes')?->name)->toBe('SNES');
});

it('keeps the keys it does not expose', function () {
    // The previous build replaced the whole config entry, which dropped `folder`
    // and collapsed the console's root onto the shared games directory.
    $folder = config('consoles.snes.folder');
    $order = config('consoles.snes.order');

    ConsoleOverrides::remember('snes', ['name' => 'SNES', 'file_extensions' => 'smc']);

    expect(config('consoles.snes.folder'))->toBe($folder)
        ->and(config('consoles.snes.order'))->toBe($order)
        ->and(Console::tryFrom('snes')?->folder)->toBe($folder);
});

it('stores nothing for a field left at its shipped value', function () {
    $shipped = config('consoles.snes.name');

    ConsoleOverrides::remember('snes', [
        'name' => $shipped,
        'file_extensions' => implode(', ', (array) config('consoles.snes.file_extensions')),
    ]);

    expect(ConsoleOverrides::has('snes'))->toBeFalse()
        ->and(AppSetting::get(AppSetting::CONSOLE_OVERRIDES))->toBe([]);
});

it('treats an emptied field as no override rather than a blank value', function () {
    $shipped = config('consoles.snes.brand');

    ConsoleOverrides::remember('snes', ['name' => 'SNES', 'brand' => '', 'file_extensions' => 'smc']);

    expect(config('consoles.snes.brand'))->toBe($shipped);
});

it('puts a console back when its override is forgotten', function () {
    $shipped = config('consoles.snes.name');

    ConsoleOverrides::remember('snes', ['name' => 'SNES', 'file_extensions' => 'smc']);
    ConsoleOverrides::forget('snes');

    // Within the same request: applying twice must restore rather than compound.
    expect(config('consoles.snes.name'))->toBe($shipped)
        ->and(ConsoleOverrides::has('snes'))->toBeFalse();
});

it('puts every console back at once', function () {
    ConsoleOverrides::remember('snes', ['name' => 'SNES', 'file_extensions' => 'smc']);
    ConsoleOverrides::remember('ps2', ['name' => 'PS2', 'file_extensions' => 'iso']);

    $snes = config('consoles.snes.name');
    $ps2 = config('consoles.ps2.name');

    ConsoleOverrides::forgetAll();

    expect(ConsoleOverrides::all())->toBe([])
        ->and(config('consoles.snes.name'))->not->toBe($snes)
        ->and(config('consoles.ps2.name'))->not->toBe($ps2);
});

it('offers the reset only once something has been edited', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('settings.consoles')
        ->assertSet('search', '')
        ->assertDontSee('Reset all')
        ->call('edit', 'snes')
        ->set('fields.name', 'SNES')
        ->call('save')
        ->assertSee('Reset all')
        ->call('restoreAll')
        ->assertDontSee('Reset all');

    expect(ConsoleOverrides::all())->toBe([]);
});

it('will not invent a console that config does not have', function () {
    ConsoleOverrides::remember('nonesuch', ['name' => 'Nonesuch', 'file_extensions' => 'rom']);

    expect(config('consoles.nonesuch'))->toBeNull()
        ->and(Console::exists('nonesuch'))->toBeFalse()
        ->and(ConsoleOverrides::has('nonesuch'))->toBeFalse();
});

it('reads a comma-separated list back as a list, case-folded', function () {
    ConsoleOverrides::remember('snes', [
        'name' => 'Super Nintendo Entertainment System',
        'file_extensions' => 'SMC , sfc,,fig ',
    ]);

    expect(config('consoles.snes.file_extensions'))->toBe(['smc', 'sfc', 'fig'])
        ->and(Console::tryFrom('snes')?->hasExtension('SMC'))->toBeTrue();
});

it('drops an override for a console config no longer ships', function () {
    AppSetting::put(AppSetting::CONSOLE_OVERRIDES, ['nonesuch' => ['name' => 'Nonesuch']]);

    expect(ConsoleOverrides::all())->toBe([]);
});

it('ignores a stored key the schema does not expose', function () {
    // `folder` is the one that matters: nothing may move a console's files.
    $folder = config('consoles.snes.folder');

    AppSetting::put(AppSetting::CONSOLE_OVERRIDES, ['snes' => ['folder' => 'elsewhere', 'name' => 'SNES']]);
    ConsoleOverrides::apply();

    expect(config('consoles.snes.folder'))->toBe($folder)
        ->and(config('consoles.snes.name'))->toBe('SNES');
});

it('shows the settings screen and saves from it', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('console-config.edit'))->assertOk();

    Livewire::test('settings.consoles')
        ->call('edit', 'snes')
        ->assertSet('fields.name', config('consoles.snes.name'))
        ->set('fields.name', 'SNES')
        ->call('save')
        ->assertHasNoErrors();

    expect(config('consoles.snes.name'))->toBe('SNES');
});

it('will not save a console without a name or a game extension', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('settings.consoles')
        ->call('edit', 'snes')
        ->set('fields.name', '')
        ->set('fields.file_extensions', '')
        ->call('save')
        ->assertHasErrors(['fields.name', 'fields.file_extensions']);

    expect(ConsoleOverrides::has('snes'))->toBeFalse();
});

it('will not take an icon that is not a path or an http url', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('settings.consoles')
        ->call('edit', 'snes')
        ->set('fields.icon', 'javascript:alert(1)')
        ->call('save')
        ->assertHasErrors(['fields.icon']);
});
