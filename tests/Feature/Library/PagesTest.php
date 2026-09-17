<?php

use App\Enums\FileRole;
use App\Jobs\ScanConsoleFolder;
use App\Models\AppSetting;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Models\MediaTypePreference;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->root = sys_get_temp_dir().'/retrobite-pages-'.Str::random(8);
    File::ensureDirectoryExists($this->root);
    config()->set('settings.games_path', $this->root);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('renders the consoles page', function () {
    File::ensureDirectoryExists($this->root.'/snes');

    $this->get(route('consoles.index'))->assertOk()->assertSee('Consoles');
});

it('scans straight away when the console folder is where convention says', function () {
    Queue::fake();
    File::ensureDirectoryExists($this->root.'/snes');

    Livewire::test('consoles.index')->call('add', 'snes');

    // Nothing to ask: the folder is already there.
    Queue::assertPushed(ScanConsoleFolder::class, fn ($job) => $job->console === 'snes');
});

it('asks where the roms are when the console folder is missing', function () {
    Queue::fake();
    File::ensureDirectoryExists($this->root.'/my-playstation-dumps');

    $component = Livewire::test('consoles.index')->call('add', 'psx');

    $component->assertSet('adding', 'psx');
    Queue::assertNothingPushed();

    $component->set('chosenFolder', 'my-playstation-dumps')->call('useFolder');

    expect(ConsoleSourceFolder::sole()->path)->toBe('my-playstation-dumps');
    Queue::assertPushed(ScanConsoleFolder::class);
});

it('refuses a folder outside the library root', function () {
    Queue::fake();
    File::ensureDirectoryExists($this->root.'/psx-elsewhere');

    Livewire::test('consoles.index')
        ->call('add', 'psx')
        ->set('chosenFolder', '../../etc')
        ->call('useFolder')
        ->assertHasErrors('chosenFolder');

    expect(ConsoleSourceFolder::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('lists games and filters them', function () {
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);
    Game::factory()->forConsole('psx')->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);

    Livewire::test('games.index')
        ->assertSee('Super Mario World')
        ->assertSee('Tekken 3')
        ->set('query', 'Tekken')
        ->assertSee('Tekken 3')
        ->assertDontSee('Super Mario World')
        ->set('query', '')
        ->set('console', 'snes')
        ->assertSee('Super Mario World')
        ->assertDontSee('Tekken 3')
        ->set('console', '')
        ->set('status', 'placeholder')
        ->assertSee('Tekken 3')
        ->assertDontSee('Super Mario World');
});

it('shows a game with its files, marking the missing ones', function () {
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Final Fantasy IX', 'slug' => 'ff9']);
    GameFile::factory()->for($game)->create(['path' => 'psx/d1.bin', 'filename' => 'd1.bin', 'role' => FileRole::Track, 'disc_number' => 1]);
    GameFile::factory()->for($game)->missing()->create(['path' => 'psx/d2.bin', 'filename' => 'd2.bin', 'role' => FileRole::Track, 'disc_number' => 2]);

    $this->get(route('games.show', $game))
        ->assertOk()
        ->assertSee('Final Fantasy IX')
        ->assertSee('d1.bin')
        ->assertSee('d2.bin')
        ->assertSee('Missing');
});

it('serves artwork only through the authed route', function () {
    Storage::fake('media');
    Storage::disk('media')->put('psx/ff9-19256/box-2d/abc.png', "\x89PNG".'x');

    $game = Game::factory()->forConsole('psx')->matched()->create();
    Media::factory()->for($game)->create(['path' => 'psx/ff9-19256/box-2d/abc.png', 'md5' => 'abc']);

    $this->get(route('media.show', ['path' => 'psx/ff9-19256/box-2d/abc.png']))->assertOk();

    // A path no row points at cannot be reached, whatever it looks like.
    $this->get(route('media.show', ['path' => 'psx/../../../etc/passwd']))->assertNotFound();

    auth()->logout();
    $this->get(route('media.show', ['path' => 'psx/ff9-19256/box-2d/abc.png']))->assertRedirect();
});

it('saves which media types to fetch', function () {
    MediaTypePreference::create(['media_type' => 'box-2D', 'enabled' => true]);
    MediaTypePreference::create(['media_type' => 'video', 'enabled' => false]);

    Livewire::test('settings.media')
        ->assertSet('enabled.box-2D', true)
        ->assertSet('autoQueue', true)
        ->set('enabled.video', true)
        ->set('enabled.box-2D', false)
        ->set('autoQueue', false)
        ->call('save');

    expect(MediaTypePreference::enabledTypes())->toBe(['video'])
        ->and(AppSetting::enabled(AppSetting::AUTO_QUEUE_MEDIA_SCRAPE, true))->toBeFalse();
});

it('keeps every library page behind the login', function () {
    auth()->logout();

    foreach ([route('consoles.index'), route('games.index'), route('media.edit')] as $url) {
        $this->get($url)->assertRedirect();
    }
});

it('renders the dashboard on an empty library and on a full one', function () {
    // $hero comes from the newest game, which does not exist before a scan.
    $this->get(route('dashboard'))->assertOk()->assertSee('Add a console');

    $game = Game::factory()->forConsole('snes')->unmatched()->create(['title' => 'Unknown Disc', 'slug' => 'unknown']);
    GameFile::factory()->for($game)->create(['path' => 'snes/unknown.sfc', 'filename' => 'unknown.sfc', 'size_bytes' => 3145728]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Unknown Disc')
        ->assertSee('unknown.sfc');
});
