<?php

use App\Enums\FileRole;
use App\Enums\GameStatus;
use App\Enums\MediaKind;
use App\Jobs\MatchGame;
use App\Jobs\ScanConsoleFolder;
use App\Jobs\ScrapeGameMedia;
use App\Models\AppSetting;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Models\User;
use App\Support\Console;
use App\Support\MediaRegions;
use App\Support\MediaTypes;
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

it('lists nothing until a console is added, whatever is on disk', function () {
    // The reason this exists: a collection copied wholesale leaves a folder for
    // every console it has ever heard of. Listing all of them buries the few
    // that hold games.
    foreach (['snes', 'psx', 'n64', 'gb', 'gba', 'megadrive'] as $folder) {
        File::ensureDirectoryExists($this->root.'/'.$folder);
    }

    Livewire::test('consoles.index')->assertSee('Your library is empty');

    // Six folders on disk, nothing in the library. That gap is the whole point.
    expect(ConsoleSourceFolder::consoles())->toHaveCount(0);

    ConsoleSourceFolder::add(new Console('snes'));

    Livewire::test('consoles.index')->assertSee('Super Nintendo');

    expect(ConsoleSourceFolder::consoles()->pluck('key')->all())->toBe(['snes']);
});

it('adds a console without asking when its folder is where convention says', function () {
    Queue::fake();
    File::ensureDirectoryExists($this->root.'/snes');

    Livewire::test('consoles.index')->call('openAdd')->call('choose', 'snes');

    expect(ConsoleSourceFolder::sole()->console)->toBe('snes')
        ->and(ConsoleSourceFolder::sole()->path)->toBe('snes');

    Queue::assertPushed(ScanConsoleFolder::class, fn ($job) => $job->console === 'snes');
});

it('asks where the roms are when the console folder is missing', function () {
    Queue::fake();
    File::ensureDirectoryExists($this->root.'/my-playstation-dumps');

    $component = Livewire::test('consoles.index')
        ->call('openAdd')
        // Not just the state: the modal has to actually open. Asserting only
        // that a property was set is what let a dead button ship — Flux modals
        // have no open prop, so the page looked unresponsive.
        ->assertDispatched('modal-show', name: 'add-console')
        ->call('choose', 'psx');

    $component->assertSet('adding', 'psx');

    // Nothing is added until a folder is chosen.
    expect(ConsoleSourceFolder::count())->toBe(0);
    Queue::assertNothingPushed();

    $component->set('chosenFolder', 'my-playstation-dumps')->call('useFolder')
        ->assertDispatched('modal-close', name: 'add-console')
        ->assertSet('adding', '');

    expect(ConsoleSourceFolder::sole()->path)->toBe('my-playstation-dumps');
    Queue::assertPushed(ScanConsoleFolder::class);
});

it('searches the consoles on offer by name, brand and key', function () {
    $component = Livewire::test('consoles.index')->call('openAdd');

    // Brand as well as name: "sega" has to find the Dreamcast, which is not
    // called that.
    $component->set('search', 'sega')->assertSee('Dreamcast')->assertDontSee('Super Nintendo');
    $component->set('search', 'super nintendo')->assertSee('Super Nintendo')->assertDontSee('Dreamcast');
});

it('does not offer a console that is already in the library', function () {
    ConsoleSourceFolder::add(new Console('snes'));

    // Searching for it finds nothing, because it is no longer on offer. Its own
    // card is elsewhere on the page, so this asks the list rather than the DOM.
    Livewire::test('consoles.index')
        ->call('openAdd')
        ->set('search', 'super nintendo')
        ->assertSee('Nothing matches that.');
});

it('removes a console from the library without touching its games', function () {
    File::ensureDirectoryExists($this->root.'/snes');
    ConsoleSourceFolder::add(new Console('snes'));
    $game = Game::factory()->forConsole('snes')->create(['title' => 'Super Mario World', 'slug' => 'smw']);

    Livewire::test('consoles.index')->call('remove', 'snes')->assertSee('Your library is empty');

    expect(ConsoleSourceFolder::count())->toBe(0)
        // A decision about what to look at, not about what to keep.
        ->and(Game::whereKey($game->id)->exists())->toBeTrue()
        ->and(File::isDirectory($this->root.'/snes'))->toBeTrue();
});

it('refuses a folder outside the library root', function () {
    Queue::fake();
    File::ensureDirectoryExists($this->root.'/psx-elsewhere');

    Livewire::test('consoles.index')
        ->call('openAdd')
        ->call('choose', 'psx')
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

it('opens every artwork in one viewer, captioned by kind and region', function () {
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Final Fantasy IX', 'slug' => 'ff9']);

    $cover = Media::factory()->for($game)->ofType('box-2D', 'eu')->create();
    $logo = Media::factory()->for($game)->ofType('wheel', 'eu')->create();
    $clip = Media::factory()->for($game)->ofType('video')->create();

    $page = $this->get(route('games.show', $game))->assertOk();

    // One set, passed whole, so the strip and the hero cover agree on it.
    $page->assertSeeHtml('data-lightbox-images');

    // The hero cover is keyed by the same path its media row holds, which is
    // how it opens the viewer in the right place without knowing its position.
    $page->assertSeeHtml('data-lightbox="'.$cover->path.'"');

    // The logo is artwork, so it is in the set and its thumbnail opens the
    // viewer — but the copy beside the title is not a way in, or the header
    // would be a gallery. Asserted as the whole tag, since the attribute alone
    // appears legitimately on the thumbnail.
    $page->assertSeeHtml(
        '<img src="'.route('media.show', ['path' => $logo->path]).'" alt="Final Fantasy IX" class="h-auto w-28 shrink-0" />'
    );

    $page->assertSee('Cover · Europe');

    // A type filling no slot we name keeps the provider's own word for it.
    $page->assertSee($clip->screenscraper_type);
});

it('keeps screenshots and thumbnails out of the login backdrop', function () {
    $game = Game::factory()->forConsole('psx')->matched()->create();

    $wallpaper = Media::factory()->for($game)->ofType('fanart')->create(['size_bytes' => 400_000]);
    // The provider files in-game screenshots as backdrops beside real key art.
    Media::factory()->for($game)->ofType('ss')->create(['size_bytes' => 400_000]);
    // Key art by type, thumbnail by size — full-bleed would expose it.
    Media::factory()->for($game)->ofType('fanart')->create(['size_bytes' => 4_000]);

    expect(Media::query()->wallpaper()->pluck('id')->all())->toBe([$wallpaper->id]);
});

it('leads with the biggest copy when the provider held several', function () {
    $game = Game::factory()->forConsole('psx')->matched()->create();

    Media::factory()->for($game)->ofType('fanart')->create(['size_bytes' => 120_000]);
    $biggest = Media::factory()->for($game)->ofType('fanart')->create(['size_bytes' => 900_000]);
    // A less preferred type never wins on size alone.
    Media::factory()->for($game)->ofType('ss')->create(['size_bytes' => 2_000_000]);

    expect($game->load('media')->artwork(MediaKind::Backdrop)?->id)->toBe($biggest->id);
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
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    Livewire::test('settings.media')
        ->assertSet('enabled.box-2D', true)
        ->assertSet('enabled.video', false)
        ->assertSet('autoQueue', true)
        ->assertSet('scanlines', true)
        ->set('enabled.video', true)
        ->set('enabled.box-2D', false)
        ->set('autoQueue', false)
        ->set('scanlines', false)
        ->call('save');

    expect(MediaTypes::enabled())->toBe(['video'])
        ->and(AppSetting::enabled(AppSetting::AUTO_QUEUE_MEDIA_SCRAPE))->toBeFalse()
        ->and(AppSetting::enabled(AppSetting::UI_SCANLINES))->toBeFalse();
});

it('filters by genre, splitting the comma-separated list the provider sends', function () {
    Game::factory()->forConsole('snes')->matched()->create([
        'title' => 'Super Mario World', 'slug' => 'smw', 'genre' => 'Platform',
    ]);
    Game::factory()->forConsole('psx')->matched()->create([
        'title' => 'Vagrant Story', 'slug' => 'vagrant', 'genre' => 'Tactical RPG, Role Playing Game',
    ]);
    // The provider also spells compound genres with a slash, which is part of
    // one entry rather than two.
    Game::factory()->forConsole('psx')->matched()->create([
        'title' => 'Fighting Force', 'slug' => 'ff', 'genre' => 'Action / Beat\'em Up, Action',
    ]);

    $component = Livewire::test('games.index');

    // One entry per genre, however many games carry it, sorted.
    expect($component->instance()->genres->all())->toBe([
        'Action',
        "Action / Beat'em Up",
        'Platform',
        'Role Playing Game',
        'Tactical RPG',
    ]);

    $component->set('genre', 'Role Playing Game')
        ->assertSee('Vagrant Story')
        ->assertDontSee('Super Mario World');

    // Matched as a whole entry: "Action" must not drag in every game whose
    // genre merely starts with it.
    $component->set('genre', 'Action')
        ->assertSee('Fighting Force')
        ->assertDontSee('Vagrant Story');
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

it('identifies one game from the list', function () {
    Queue::fake();
    $game = Game::factory()->forConsole('psx')->create(['title' => 'Unknown Disc', 'slug' => 'unknown']);
    GameFile::factory()->for($game)->create([
        'path' => 'psx/a.bin', 'filename' => 'a.bin', 'extension' => 'bin', 'role' => FileRole::Track,
    ]);

    Livewire::test('games.index')->assertSee('Identify')->call('identify', $game->id);

    Queue::assertPushed(MatchGame::class, fn ($job) => $job->gameId === $game->id);
});

it('offers no identify button for a game already identified', function () {
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);
    GameFile::factory()->for($game)->create(['path' => 'psx/t.bin', 'filename' => 't.bin', 'role' => FileRole::Track]);

    expect($game->canBeIdentified())->toBeFalse()
        ->and($game->blockedFromLookup())->toBe('Already identified.');

    Livewire::test('games.index')->assertDontSee('Identify');
});

it('says why a game cannot be identified instead of offering a dead button', function () {
    // A playlist with no data track: nothing here the provider indexes.
    $game = Game::factory()->forConsole('psx')->create(['title' => 'Orphan', 'slug' => 'orphan']);
    GameFile::factory()->for($game)->role(FileRole::Playlist)->create(['path' => 'psx/o.m3u', 'filename' => 'o.m3u']);

    $this->get(route('games.show', $game))
        ->assertOk()
        ->assertSee('No file here is one the provider can identify.');

    Livewire::test('games.show', ['game' => $game])
        ->call('identify')
        ->assertSet('awaiting', null);
});

it('waits for an answer on the game page, then stops', function () {
    Queue::fake();
    $game = Game::factory()->forConsole('psx')->create(['title' => 'Unknown Disc', 'slug' => 'unknown']);
    GameFile::factory()->for($game)->create([
        'path' => 'psx/a.bin', 'filename' => 'a.bin', 'extension' => 'bin', 'role' => FileRole::Track,
    ]);

    $component = Livewire::test('games.show', ['game' => $game])
        ->assertSee('Identify')
        ->call('identify');

    $component->assertSee('Waiting for ScreenScraper')->assertNotSet('awaiting', null);

    Queue::assertPushed(MatchGame::class);

    // Nothing has answered yet, so the page keeps waiting.
    $component->call('checkAnswer')->assertNotSet('awaiting', null);

    $game->update(['status' => GameStatus::Unmatched]);

    // The row changed, so the page stops asking — however the answer went.
    $component->call('checkAnswer')->assertSet('awaiting', null);
});

it('gives up waiting when a retry answers with no change at all', function () {
    Queue::fake();
    $game = Game::factory()->forConsole('psx')->unmatched()->create(['title' => 'Odd', 'slug' => 'odd']);
    GameFile::factory()->for($game)->create([
        'path' => 'psx/o.bin', 'filename' => 'o.bin', 'extension' => 'bin', 'role' => FileRole::Track,
    ]);

    $component = Livewire::test('games.show', ['game' => $game])->call('identify');

    // A retry that misses again leaves the game exactly as it was, so there is
    // no answer to notice — only a wait that has to end by itself.
    $component->call('checkAnswer')->assertNotSet('awaiting', null);

    $this->travel(3)->minutes();

    $component->call('checkAnswer')->assertSet('awaiting', null);
});

it('offers a retry on a game the provider could not name', function () {
    $game = Game::factory()->forConsole('psx')->unmatched()->create(['title' => 'Odd Dump', 'slug' => 'odd']);
    GameFile::factory()->for($game)->create([
        'path' => 'psx/o.bin', 'filename' => 'o.bin', 'extension' => 'bin', 'role' => FileRole::Track,
    ]);

    // Worth offering: a rename or a fresh dump is exactly when spending the
    // scarce failed-lookup allowance again is the user's call to make.
    $this->get(route('games.show', $game))->assertOk()->assertSee('Try identifying again');
});

it('fetches artwork for an identified game from the list', function () {
    Queue::fake();
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);
    GameFile::factory()->for($game)->create(['path' => 'psx/t.bin', 'filename' => 't.bin', 'role' => FileRole::Track]);

    Livewire::test('games.index')->assertSee('Artwork')->call('fetchMedia', $game->id);

    Queue::assertPushed(ScrapeGameMedia::class, fn ($job) => $job->gameId === $game->id);
});

it('offers artwork only once a game has been identified', function () {
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    $placeholder = Game::factory()->forConsole('psx')->create(['title' => 'Unknown', 'slug' => 'unknown']);
    GameFile::factory()->for($placeholder)->create(['path' => 'psx/u.bin', 'filename' => 'u.bin', 'role' => FileRole::Track]);

    expect($placeholder->canFetchMedia())->toBeFalse()
        ->and($placeholder->blockedFromMediaScrape())->toContain('Identify the game first');

    // The list offers Identify instead — one action per row, the one that applies.
    Livewire::test('games.index')->assertSee('Identify')->assertDontSee('Artwork');
});

it('says so when no media type is switched on rather than queueing nothing', function () {
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);

    // Written out, because an absent setting now means the shipped selection.
    // Switching everything off is a choice somebody made, and only an empty
    // stored list says so.
    AppSetting::put(AppSetting::MEDIA_TYPES, []);

    expect(MediaTypes::enabled())->toBe([])
        ->and($game->canFetchMedia())->toBeFalse();

    // The job would run, find nothing switched on and return having done
    // nothing at all.
    $this->get(route('games.show', $game))->assertOk()->assertSee('No media types are switched on');
});

it('waits for artwork to arrive, then stops', function () {
    Queue::fake();
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);

    $component = Livewire::test('games.show', ['game' => $game])
        ->assertSee('Fetch artwork')
        ->call('fetchMedia');

    // Asserted on what a person sees: assertNotSet compares loosely, and
    // 0 == null in PHP, so a count of zero would satisfy either claim.
    $component->assertSet('fetchingFrom', 0)->assertSee('Fetching artwork');
    Queue::assertPushed(ScrapeGameMedia::class);

    $component->call('checkMedia')->assertSee('Fetching artwork');

    Media::factory()->for($game)->create();

    // Artwork leaves the game row untouched, so arrival is counted, not
    // fingerprinted.
    $component->call('checkMedia')->assertDontSee('Fetching artwork');
});

it('gives up waiting when the provider holds no artwork', function () {
    Queue::fake();
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Obscure', 'slug' => 'obscure']);

    $component = Livewire::test('games.show', ['game' => $game])->call('fetchMedia');

    $component->call('checkMedia')->assertSee('Fetching artwork');

    $this->travel(3)->minutes();

    $component->call('checkMedia')->assertDontSee('Fetching artwork');
});

it('saves a preferred media region', function () {
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    Livewire::test('settings.media')
        ->assertSet('region', '')
        ->assertSee('Preferred region')
        ->set('region', 'se')
        ->call('save');

    expect(MediaRegions::preferred())->toBe('se')
        // The preference leads, and the neutral entries stay behind it.
        ->and(MediaRegions::chain())->toBe(['se', 'ss', 'wor', 'eu', 'us', 'jp']);
});
