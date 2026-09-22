<?php

use App\Exceptions\LibraryPathException;
use App\Jobs\ScanConsoleFolder;
use App\Models\ConsoleSourceFolder;
use App\Models\User;
use App\Support\Console;
use App\Support\LibraryPath;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Adding a console asks as little as it can.
 *
 * The folder step only appears when convention does not already answer it, and
 * the layout step only when the console knows more than one arrangement — which
 * is one console out of 135 today. Everything is written once, at the end, so a
 * wizard somebody walks away from leaves nothing behind.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-step-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/ps2');
    File::ensureDirectoryExists($this->root.'/snes');
    config()->set('settings.games_path', $this->root);

    $this->actingAs(User::factory()->create());

    Bus::fake();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('asks about the layout for a console that knows more than one', function () {
    Livewire::test('consoles.index')
        ->call('choose', 'ps2')
        ->assertSet('step', 'layout')
        ->assertSet('adding', 'ps2');

    // Nothing written yet: the choice is still open.
    expect(ConsoleSourceFolder::count())->toBe(0);
});

it('writes the console down once the layout comes back', function () {
    Livewire::test('consoles.index')
        ->call('choose', 'ps2')
        ->dispatch('layout-chosen', console: 'ps2', layout: 'opl')
        ->assertSet('step', 'console')
        ->assertSet('adding', '');

    $row = ConsoleSourceFolder::sole();

    expect($row->console)->toBe('ps2')
        ->and($row->path)->toBe('ps2')
        ->and($row->layout)->toBe('opl')
        ->and(ConsoleSourceFolder::layoutKeyFor(new Console('ps2')))->toBe('opl');

    Bus::assertDispatched(ScanConsoleFolder::class);
});

it('asks nothing extra of a console with one arrangement', function () {
    Livewire::test('consoles.index')
        ->call('choose', 'snes')
        ->assertSet('step', 'console');

    $row = ConsoleSourceFolder::sole();

    // Null, not 'custom': nobody was asked, so the console's own default
    // stands and keeps standing if that default ever changes.
    expect($row->console)->toBe('snes')
        ->and($row->layout)->toBeNull()
        ->and(ConsoleSourceFolder::layoutKeyFor(new Console('snes')))->toBe('custom');

    Bus::assertDispatched(ScanConsoleFolder::class);
});

it('asks for a folder first when there is no folder', function () {
    File::deleteDirectory($this->root.'/ps2');
    File::ensureDirectoryExists($this->root.'/somewhere-else');

    Livewire::test('consoles.index')
        ->call('choose', 'ps2')
        ->assertSet('step', 'folder')
        ->set('chosenFolder', 'somewhere-else')
        ->call('useFolder')
        ->assertSet('step', 'layout')
        // Still nothing written: the folder is held until the layout is known.
        ->assertHasNoErrors();

    expect(ConsoleSourceFolder::count())->toBe(0);
});

it('keeps the chosen folder through the layout step', function () {
    File::deleteDirectory($this->root.'/ps2');
    File::ensureDirectoryExists($this->root.'/somewhere-else');

    Livewire::test('consoles.index')
        ->call('choose', 'ps2')
        ->set('chosenFolder', 'somewhere-else')
        ->call('useFolder')
        ->dispatch('layout-chosen', console: 'ps2', layout: 'retroarch');

    $row = ConsoleSourceFolder::sole();

    expect($row->path)->toBe('somewhere-else')
        ->and($row->layout)->toBe('retroarch');
});

it('goes back to the folder step rather than out of the modal', function () {
    File::deleteDirectory($this->root.'/ps2');
    File::ensureDirectoryExists($this->root.'/somewhere-else');

    Livewire::test('consoles.index')
        ->call('choose', 'ps2')
        ->set('chosenFolder', 'somewhere-else')
        ->call('useFolder')
        ->dispatch('layout-cancelled')
        ->assertSet('step', 'folder');
});

it('changes the layout of a console already in the library', function () {
    ConsoleSourceFolder::add(new Console('ps2'), null, 'custom');

    Livewire::test('consoles.index')
        ->call('changeLayout', 'ps2')
        ->assertSet('step', 'layout')
        ->assertSet('editingLayout', true)
        ->dispatch('layout-chosen', console: 'ps2', layout: 'opl');

    expect(ConsoleSourceFolder::sole()->layout)->toBe('opl');

    // Re-read, because what counts as a game just changed.
    Bus::assertDispatched(ScanConsoleFolder::class);
});

it('will not store a layout the console does not offer', function () {
    ConsoleSourceFolder::add(new Console('snes'), null, null);

    ConsoleSourceFolder::setLayout(new Console('snes'), 'opl');

    expect(ConsoleSourceFolder::sole()->layout)->toBeNull();
});

it('offers the picker only where there is a choice to make', function () {
    ConsoleSourceFolder::add(new Console('ps2'));
    ConsoleSourceFolder::add(new Console('snes'));

    Livewire::test('consoles.index')
        ->assertSee('Change layout');

    ConsoleSourceFolder::forget(new Console('ps2'));

    Livewire::test('consoles.index')
        ->assertDontSee('Change layout');
});

/**
 * The folder step used to be a dead end: an empty library root meant an empty
 * picker and a disabled button. Config already names the folder, so it is
 * offered. Choosing a layout then makes whatever that layout expects and the
 * drive does not have — the missing ones only, so a drive already arranged
 * that way is left exactly as it was.
 */
it('offers to make the folder the console names', function () {
    File::deleteDirectory($this->root.'/ps2');

    Livewire::test('consoles.index')
        ->call('choose', 'ps2')
        ->assertSet('step', 'folder')
        ->assertSee('Create ps2/')
        ->call('createFolder')
        ->assertSet('step', 'layout')
        ->assertHasNoErrors();

    expect(File::isDirectory($this->root.'/ps2'))->toBeTrue();
});

it('offers it beside the folders that are already there', function () {
    File::deleteDirectory($this->root.'/ps2');
    File::ensureDirectoryExists($this->root.'/roms');

    // Having somewhere to point at does not make the conventional folder any
    // less useful — a library of folders named some other way is as common as
    // an empty one.
    Livewire::test('consoles.index')
        ->call('choose', 'ps2')
        ->assertSet('creatable', 'ps2')
        ->assertSee('Create ps2/');
});

it('does not offer to make a folder that is there', function () {
    Livewire::test('consoles.index')
        ->set('adding', 'ps2')
        ->assertSet('creatable', null);
});

it('writes the conventional path after making the folder', function () {
    File::deleteDirectory($this->root.'/ps2');

    Livewire::test('consoles.index')
        ->call('choose', 'ps2')
        ->call('createFolder')
        ->dispatch('layout-chosen', console: 'ps2', layout: 'opl');

    expect(ConsoleSourceFolder::sole()->path)->toBe('ps2');
});

it('adds a single-layout console outright once the folder is made', function () {
    File::deleteDirectory($this->root.'/snes');

    Livewire::test('consoles.index')
        ->call('choose', 'snes')
        ->call('createFolder')
        ->assertSet('step', 'console');

    expect(ConsoleSourceFolder::sole()->console)->toBe('snes')
        ->and(File::isDirectory($this->root.'/snes'))->toBeTrue();

    Bus::assertDispatched(ScanConsoleFolder::class);
});

it('refuses to build a tree on a library root that is not mounted', function () {
    $missing = $this->root.'/not-mounted';
    config()->set('settings.games_path', $missing);

    Livewire::test('consoles.index')
        ->call('choose', 'ps2')
        ->call('createFolder')
        ->assertHasErrors('chosenFolder')
        // A GAMES_PATH that failed to mount would otherwise get the tree built
        // on the container's own filesystem: right in the interface, invisible
        // over the share, gone on the next `up`.
        ->assertSee('Check the library mount');

    expect(File::exists($missing))->toBeFalse()
        ->and(ConsoleSourceFolder::count())->toBe(0);
});

it('never says where on disk it failed', function () {
    config()->set('settings.games_path', $this->root.'/not-mounted');

    $component = Livewire::test('consoles.index')->call('choose', 'ps2')->call('createFolder');

    $message = $component->errors()->first('chosenFolder');

    expect($message)->not->toContain('/')
        ->and($message)->not->toContain($this->root);
});

it('refuses a folder name that climbs out of the library', function () {
    config()->set('consoles.ps2.folder', '../evil');

    expect(fn () => app(LibraryPath::class)->createConsoleRoot(new Console('ps2')))
        ->toThrow(LibraryPathException::class);

    expect(File::exists(dirname($this->root).'/evil'))->toBeFalse();
});

it('builds the drive OPL expects', function () {
    Livewire::test('consoles.index')
        ->call('choose', 'ps2')
        ->dispatch('layout-chosen', console: 'ps2', layout: 'opl');

    foreach (['DVD', 'CD', 'ART', 'CFG', 'VMC', 'THM'] as $directory) {
        expect(File::isDirectory($this->root.'/ps2/'.$directory))->toBeTrue();
    }

    // OPL makes these itself the day somebody runs homebrew or loads a cheat,
    // and an empty one explains nothing.
    expect(File::exists($this->root.'/ps2/APPS'))->toBeFalse()
        ->and(File::exists($this->root.'/ps2/CHT'))->toBeFalse();
});

it('leaves the folders that are already there alone', function () {
    File::ensureDirectoryExists($this->root.'/ps2/DVD');
    File::put($this->root.'/ps2/DVD/Game.iso', 'x');

    Livewire::test('consoles.index')
        ->call('choose', 'ps2')
        ->dispatch('layout-chosen', console: 'ps2', layout: 'opl');

    expect(File::get($this->root.'/ps2/DVD/Game.iso'))->toBe('x')
        ->and(File::isDirectory($this->root.'/ps2/CD'))->toBeTrue();
});

it('makes nothing on a drive already arranged that way', function () {
    foreach (['DVD', 'CD', 'ART', 'CFG', 'VMC', 'THM'] as $directory) {
        File::ensureDirectoryExists($this->root.'/ps2/'.$directory);
    }
    File::put($this->root.'/ps2/DVD/Game.iso', 'x');

    Livewire::test('consoles.index')
        ->call('choose', 'ps2')
        ->dispatch('layout-chosen', console: 'ps2', layout: 'opl')
        // Nothing was made, so nothing is claimed.
        ->assertDontSee('Created');

    expect(File::get($this->root.'/ps2/DVD/Game.iso'))->toBe('x');
});

it('makes nothing for the layout that imposes nothing', function () {
    Livewire::test('consoles.index')
        ->call('choose', 'ps2')
        ->dispatch('layout-chosen', console: 'ps2', layout: 'custom');

    expect(File::directories($this->root.'/ps2'))->toBe([]);
});

it('lays out the thumbnail tree for retroarch', function () {
    Livewire::test('consoles.index')
        ->call('choose', 'ps2')
        ->dispatch('layout-chosen', console: 'ps2', layout: 'retroarch');

    foreach (['Named_Boxarts', 'Named_Snaps', 'Named_Titles'] as $kind) {
        expect(File::isDirectory($this->root.'/ps2/media/'.$kind))->toBeTrue();
    }

    // Nested, so the gate has to walk more than one segment to get there.
    expect(File::isDirectory($this->root.'/ps2/media'))->toBeTrue()
        // And none of OPL's, which is a different arrangement entirely.
        ->and(File::exists($this->root.'/ps2/DVD'))->toBeFalse();
});

it('builds the drive when the layout is changed to opl', function () {
    ConsoleSourceFolder::add(new Console('ps2'), null, 'custom');

    // The case this matters most for: a console already in the library, on a
    // layout whose folders were never needed until now.
    Livewire::test('consoles.index')
        ->call('changeLayout', 'ps2')
        ->dispatch('layout-chosen', console: 'ps2', layout: 'opl');

    expect(File::isDirectory($this->root.'/ps2/DVD'))->toBeTrue()
        ->and(File::isDirectory($this->root.'/ps2/CFG'))->toBeTrue();
});

it('still adds the console when a folder cannot be made', function () {
    chmod($this->root.'/ps2', 0o500);

    Livewire::test('consoles.index')
        ->call('choose', 'ps2')
        ->dispatch('layout-chosen', console: 'ps2', layout: 'opl');

    chmod($this->root.'/ps2', 0o755);

    // Somewhere the app cannot write is still somewhere it can read.
    expect(ConsoleSourceFolder::sole()->layout)->toBe('opl');

    Bus::assertDispatched(ScanConsoleFolder::class);
})->skip(fn () => posix_geteuid() === 0, 'root ignores the permission bits');

/**
 * Changing a layout should start from the one in force. Opening the picker on
 * its default instead would quietly offer to change a console back to Custom
 * as the path of least resistance.
 */
it('opens the picker on the layout in force', function () {
    ConsoleSourceFolder::add(new Console('ps2'), null, 'opl');

    Livewire::test('consoles.choose-layout', ['console' => 'ps2', 'editing' => true])
        ->assertSet('layout', 'opl')
        ->assertSet('current', 'opl')
        ->assertSee('Current');
});

it('opens on the console default when it has never been asked', function () {
    ConsoleSourceFolder::add(new Console('ps2'), null, null);

    Livewire::test('consoles.choose-layout', ['console' => 'ps2', 'editing' => true])
        ->assertSet('layout', 'custom');
});

it('claims nothing is current for a console being added', function () {
    // Not in the library yet, so no arrangement is in force. Marking its
    // default as current would claim something about a drive nobody has
    // looked at.
    Livewire::test('consoles.choose-layout', ['console' => 'ps2'])
        ->assertSet('current', null)
        ->assertSet('layout', 'custom')
        ->assertDontSee('Current');
});

it('says on the card how a console is being read', function () {
    ConsoleSourceFolder::add(new Console('ps2'), null, 'opl');

    Livewire::test('consoles.index')->assertSee('Open PS2 Loader');
});

it('says nothing about layout where there was no choice', function () {
    ConsoleSourceFolder::add(new Console('snes'), null, null);

    // One arrangement means the same words on every card, meaning nothing.
    Livewire::test('consoles.index')
        ->assertSee('snes')
        ->assertDontSee('However it already is');
});
