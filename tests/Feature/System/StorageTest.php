<?php

use App\Models\User;
use App\Support\LibraryStorage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * What the library takes up, and the three places that report it.
 *
 * The figure is the library, not the device. A retroBITE host is usually
 * somebody's desktop, where the disk is mostly an operating system and years of
 * other things — reporting the device's own used figure said the drive was 84 %
 * full and said nothing at all about the games, which were 7 % of it.
 *
 * The bytes are real. The library root is a temp directory with files of known
 * size in it, so the numerator is exact on any machine; only the free space
 * underneath belongs to whatever is running the suite, and the assertions treat
 * it as a relation rather than a value.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-storage-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/ps2/DVD');
    config()->set('settings.games_path', $this->root);

    LibraryStorage::forget();
});

afterEach(function () {
    File::deleteDirectory($this->root);
    LibraryStorage::forget();
});

it('measures the library rather than the drive it sits on', function () {
    File::put($this->root.'/ps2/DVD/one.iso', str_repeat('x', 4096));
    File::put($this->root.'/ps2/DVD/two.iso', str_repeat('x', 2048));

    $storage = LibraryStorage::current();

    expect($storage->used)->toBe(6144)
        // The denominator is what the library could grow into, so the bar still
        // fills as the drive does — whatever it was that filled it.
        ->and($storage->free)->toBeGreaterThan(0)
        ->and($storage->total())->toBe($storage->used + $storage->free);
});

it('counts what the database never imported', function () {
    // A scan imports ROMs. The loader exports write artwork and configs beside
    // them, and neither is a row in game_files — but both take room.
    File::ensureDirectoryExists($this->root.'/ps2/ART');
    File::put($this->root.'/ps2/DVD/game.iso', str_repeat('x', 1024));
    File::put($this->root.'/ps2/ART/game_COV.jpg', str_repeat('x', 512));

    expect(LibraryStorage::current()->used)->toBe(1536);
});

it('says nothing rather than zero when the library folder is not there', function () {
    config()->set('settings.games_path', $this->root.'/not-mounted');

    expect(LibraryStorage::current())->toBeNull();
});

it('does not walk the library again on every poll', function () {
    File::put($this->root.'/ps2/DVD/one.iso', str_repeat('x', 1024));

    expect(LibraryStorage::current()->used)->toBe(1024);

    File::put($this->root.'/ps2/DVD/two.iso', str_repeat('x', 1024));

    // Still the cached reading: the sidebar asks once a minute on every open
    // tab, and walking a library of thousands of files each time is the whole
    // reason this one is cached at all.
    expect(LibraryStorage::current()->used)->toBe(1024);

    LibraryStorage::forget();

    expect(LibraryStorage::current()->used)->toBe(2048);
});

it('draws a dash and an empty track rather than inventing a reading', function () {
    config()->set('settings.games_path', $this->root.'/not-mounted');

    // A bar at zero would say the library is empty, which is the one thing an
    // unreadable mount is not known to be.
    Livewire::test('library-storage')
        ->assertSee(__('Storage'))
        ->assertSee('—')
        ->assertSeeHtml('width: 0%');
});

it('draws the figures it measured, and keeps looking', function () {
    File::put($this->root.'/ps2/DVD/one.iso', str_repeat('x', 4096));

    $storage = LibraryStorage::current();

    Livewire::test('library-storage')
        ->assertSee(Number::fileSize($storage->used, 1))
        ->assertSee(Number::fileSize($storage->total(), 1))
        ->assertDontSee('—')
        ->assertSeeHtml('wire:poll.60s');
});

it('rides along on every page of the application', function () {
    $this->actingAs(User::factory()->create());
    File::put($this->root.'/ps2/DVD/one.iso', str_repeat('x', 4096));

    // The library total, not the word Storage: the dashboard grid has a cell
    // with that label too, so asserting it would pass with the sidebar block
    // deleted.
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee(Number::fileSize(LibraryStorage::current()->total(), 1));
});

it('shows the dashboard what the library takes and what is left', function () {
    $this->actingAs(User::factory()->create());
    File::put($this->root.'/ps2/DVD/one.iso', str_repeat('x', 4096));

    $storage = LibraryStorage::current();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('of :total, :free free', [
            'total' => Number::fileSize($storage->total(), 1),
            'free' => Number::fileSize($storage->free, 1),
        ]))
        // The catalogue sum this cell used to carry, and its caption.
        ->assertDontSee(__('on disk'));
});

it('keeps the dashboard cell when the mount cannot be read', function () {
    $this->actingAs(User::factory()->create());

    config()->set('settings.games_path', $this->root.'/not-mounted');

    // Six cells over two and three columns. Dropping one leaves a hole, so this
    // one dashes where the login page drops.
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('library folder unreadable'));
});

it('shows the login page the library rather than the catalogue', function () {
    File::put($this->root.'/ps2/DVD/one.iso', str_repeat('x', 4096));
    config()->set('settings.login_show_stats', true);

    $storage = LibraryStorage::current();

    // Fortify mounts the login screen at /.
    $this->get('/')
        ->assertOk()
        ->assertSee(Number::fileSize($storage->used, 1))
        ->assertSee(__('of :total used', ['total' => Number::fileSize($storage->total(), 1)]));
});

it('drops the login tile rather than dashing it when the mount is gone', function () {
    config()->set('settings.login_show_stats', true);
    config()->set('settings.games_path', $this->root.'/not-mounted');

    // A stat tile is a bare number over a caption. An em dash there reads as a
    // broken page, and the row is centred, so two sit as well as three.
    $this->get('/')
        ->assertOk()
        ->assertSee('games catalogued')
        ->assertDontSee('used');
});
