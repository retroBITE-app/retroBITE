<?php

use App\Enums\FileRole;
use App\Enums\GameStatus;
use App\Enums\MediaKind;
use App\Jobs\InspectGameFile;
use App\Jobs\MatchGame;
use App\Jobs\MeasureLibrary;
use App\Jobs\RateGame;
use App\Jobs\ScanConsoleFolder;
use App\Jobs\ScrapeGameMedia;
use App\Jobs\WriteConsoleExports;
use App\Models\AppSetting;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Models\User;
use App\Services\LibraryScanner;
use App\Support\Console;
use App\Support\ExportProgress;
use App\Support\MediaRegions;
use App\Support\MediaTypes;
use App\Support\Scanning\FolderCounts;
use App\Support\SystemActivity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
    File::ensureDirectoryExists($this->root.'/my-snes-dumps');

    // A console with one layout, so choosing its folder is the last step.
    $component = Livewire::test('consoles.index')
        ->call('openAdd')
        // Not just the state: the modal has to actually open. Asserting only
        // that a property was set is what let a dead button ship — Flux modals
        // have no open prop, so the page looked unresponsive.
        ->assertDispatched('modal-show', name: 'add-console')
        ->call('choose', 'snes');

    $component->assertSet('adding', 'snes');

    // Nothing is added until a folder is chosen.
    expect(ConsoleSourceFolder::count())->toBe(0);
    Queue::assertNothingPushed();

    $component->set('chosenFolder', 'my-snes-dumps')->call('useFolder')
        ->assertDispatched('modal-close', name: 'add-console')
        ->assertSet('adding', '');

    expect(ConsoleSourceFolder::sole()->path)->toBe('my-snes-dumps');
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

it('fetches artwork for a whole console, skipping the games that already have some', function () {
    Queue::fake();
    ConsoleSourceFolder::add(new Console('snes'));

    $bare = Game::factory()->forConsole('snes')->create(['screenscraper_id' => 101]);
    $dressed = Game::factory()->forConsole('snes')->create(['screenscraper_id' => 102]);
    Media::factory()->for($dressed)->ofType('box-2D', 'eu')->create();

    // A placeholder has no provider id, and artwork is fetched by provider id.
    Game::factory()->forConsole('snes')->create(['screenscraper_id' => null, 'status' => GameStatus::Placeholder]);

    // And another console's game is not this console's business.
    Game::factory()->forConsole('nes')->create(['screenscraper_id' => 103]);

    Livewire::test('consoles.index')->call('fetchMedia', 'snes');

    Queue::assertPushed(ScrapeGameMedia::class, 1);
    Queue::assertPushed(ScrapeGameMedia::class, fn ($job) => $job->gameId === $bare->id);
});

it('re-fetches every identified game when asked to start over', function () {
    Queue::fake();
    ConsoleSourceFolder::add(new Console('snes'));

    $bare = Game::factory()->forConsole('snes')->create(['screenscraper_id' => 101]);
    $dressed = Game::factory()->forConsole('snes')->create(['screenscraper_id' => 102]);
    Media::factory()->for($dressed)->ofType('box-2D', 'eu')->create();

    // What somebody does after switching a media type on: the games that
    // already hold a cover are exactly the ones that need asking again.
    Livewire::test('consoles.index')->call('fetchMedia', 'snes', true);

    Queue::assertPushed(ScrapeGameMedia::class, 2);
});

it('says so rather than queueing nothing when no media types are switched on', function () {
    // Added before the fake: adding a console queues a file count, which is
    // not the queueing this test is about.
    ConsoleSourceFolder::add(new Console('snes'));
    Queue::fake();
    Game::factory()->forConsole('snes')->create(['screenscraper_id' => 101]);

    AppSetting::put(AppSetting::MEDIA_TYPES, []);

    // Every job would return having done nothing, and the page would look
    // broken rather than misconfigured.
    Livewire::test('consoles.index')
        ->call('fetchMedia', 'snes')
        ->assertDispatched('toast-show');

    Queue::assertNothingPushed();
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
        ->set('consoleFilter', 'snes')
        ->assertSee('Super Mario World')
        ->assertDontSee('Tekken 3')
        ->set('consoleFilter', '')
        ->assertSee('Super Mario World')
        ->assertSee('Tekken 3');
});

it('shows a game with its files, marking the missing ones', function () {
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Final Fantasy IX', 'slug' => 'ff9']);
    GameFile::factory()->for($game)->create(['path' => 'psx/d1.bin', 'filename' => 'd1.bin', 'role' => FileRole::Track, 'disc_number' => 1]);
    GameFile::factory()->for($game)->missing()->create(['path' => 'psx/d2.bin', 'filename' => 'd2.bin', 'role' => FileRole::Track, 'disc_number' => 2]);

    $this->get(route('games.show', $game->routeParameters()))
        ->assertOk()
        ->assertSee('Final Fantasy IX')
        ->assertSee('d1.bin')
        ->assertSee('d2.bin')
        ->assertSee('Missing');
});

/**
 * The disc's own name for itself, beside the filename it happens to be stored
 * under. The two disagree on an OPL drive by design — the file can be called
 * anything and the serial cannot — so the files panel has to say both.
 */
it('shows the serial a disc carries next to its filename', function () {
    $game = Game::factory()->forConsole('ps2')->matched()->create(['title' => 'Tekken Tag', 'slug' => 'tekken-tag']);
    GameFile::factory()->for($game)->create([
        'path' => 'ps2/DVD/SLES_503.86.Tekken Tag.iso',
        'filename' => 'SLES_503.86.Tekken Tag.iso',
        'extension' => 'iso',
        'license_id' => 'SLES_503.86',
    ]);

    $this->get(route('games.show', $game->routeParameters()))
        ->assertOk()
        ->assertSee('SLES_503.86');
});

it('says nothing where a file carries no serial', function () {
    $game = Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);
    GameFile::factory()->for($game)->create([
        'path' => 'snes/smw.sfc', 'filename' => 'smw.sfc', 'extension' => 'sfc', 'license_id' => null,
    ]);

    // An empty chip would read as a serial nobody could make out, rather than
    // as a cartridge that never had one.
    $this->get(route('games.show', $game->routeParameters()))
        ->assertOk()
        ->assertDontSeeHtml('rounded-md border border-line-strong bg-surface px-2 py-0.5 font-mono text-xs text-fg-muted');
});

it('opens every artwork in one viewer, captioned by kind and region', function () {
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Final Fantasy IX', 'slug' => 'ff9']);

    $cover = Media::factory()->for($game)->ofType('box-2D', 'eu')->create();
    $logo = Media::factory()->for($game)->ofType('wheel', 'eu')->create();
    $clip = Media::factory()->for($game)->ofType('video')->create();

    // The hero shows the title as text unless Settings → UI asks for the logo.
    AppSetting::put(AppSetting::UI_HERO_TITLE, 'logo');

    $page = $this->get(route('games.show', $game->routeParameters()))->assertOk();

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
        '<img src="'.route('media.show', ['path' => $logo->path]).'" alt="Final Fantasy IX" class="block h-auto max-h-20 w-auto max-w-full" />'
    );

    // The strip itself lives behind the Artwork tab, which the URL names, so
    // a link into it opens on the pictures rather than on the file table.
    $strip = $this->get(route('games.show', $game->routeParameters()).'?tab=artwork')->assertOk();

    $strip->assertSee('Cover · Europe');

    // A type filling no slot we name keeps the provider's own word for it.
    $strip->assertSee($clip->screenscraper_type);
});

it('puts the panels behind tabs, offering only the ones the game has', function () {
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Final Fantasy IX', 'slug' => 'ff9']);
    GameFile::factory()->for($game)->create(['path' => 'psx/d1.bin', 'filename' => 'd1.bin']);

    // The table's own column, not the filename: the Actions menu carries the
    // path too, so a filename says nothing about which panel is open.
    $table = 'Last seen';

    // No set and nothing downloaded: one tab, and its panel is the page.
    Livewire::test('games.show', ['game' => $game])
        ->assertSet('tab', '')
        ->assertSee($table)
        ->assertDontSee('Artwork');

    Media::factory()->for($game)->ofType('box-2D', 'eu')->create();

    // Artwork arriving puts a second tab up, and the table gives way to it.
    Livewire::test('games.show', ['game' => $game])
        ->assertSee('Artwork')
        ->assertDontSee('Cover · Europe')
        ->call('selectTab', 'artwork')
        ->assertSee('Cover · Europe')
        ->assertDontSee($table);

    // A tab this game does not have falls back to the first one rather than
    // leaving the page empty under the row.
    $this->get(route('games.show', $game->routeParameters()).'?tab=achievements')->assertOk()->assertSee($table);
});

it('holds the artwork in one order, whichever request asks', function () {
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Final Fantasy IX', 'slug' => 'ff9']);

    // Created back to front, so id order and slot order disagree.
    $shot = Media::factory()->for($game)->ofType('ss')->create();
    $backdrop = Media::factory()->for($game)->ofType('fanart')->create();
    $logo = Media::factory()->for($game)->ofType('wheel', 'eu')->create();
    $cover = Media::factory()->for($game)->ofType('box-2D', 'eu')->create();

    $order = fn ($component) => array_column($component->instance()->gallery, 'key');

    $expected = [$cover->path, $logo->path, $backdrop->path, $shot->path];

    $component = Livewire::test('games.show', ['game' => $game]);

    expect($order($component))->toBe($expected);

    // The same order on an update. Livewire re-resolves the model from the
    // database here, so a relation ordered once at mount would be back to
    // whatever the table hands over — which is what made the strip reshuffle
    // between clicking a tab and reloading the page.
    $component->call('selectTab', 'artwork');

    expect($order($component))->toBe($expected);

    // And after a delete, which is the case that stops insertion order being
    // insertion order at all: a replaced cover leaves a hole behind it.
    $logo->delete();

    $component->call('selectTab', 'files')->call('selectTab', 'artwork');

    expect($order($component))->toBe([$cover->path, $backdrop->path, $shot->path]);
});

it('groups the artwork by region and lets a game pick one', function () {
    AppSetting::put(AppSetting::MEDIA_REGION, 'eu');

    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Final Fantasy IX', 'slug' => 'ff9']);

    // The European box is the bigger file, which is how it used to win
    // regardless of what anybody asked for.
    $europe = Media::factory()->for($game)->ofType('box-2D', 'eu')->create(['size_bytes' => 900_000]);
    $japan = Media::factory()->for($game)->ofType('box-2D', 'jp')->create(['size_bytes' => 120_000]);
    Media::factory()->for($game)->ofType('fanart')->create();

    $component = Livewire::test('games.show', ['game' => $game])->call('selectTab', 'artwork');

    $groups = $component->instance()->galleryByRegion;

    // Chain order, and the region-less group last under a name of its own.
    expect(array_column($groups, 'label'))->toBe(['Europe', 'Japan', 'No region'])
        ->and(array_column($groups, 'selected'))->toBe([true, false, false]);

    expect($game->refresh()->load('media')->artwork(MediaKind::Cover)->id)->toBe($europe->id);

    // Choosing moves the hero cover, not just the label on the group.
    $component->call('useRegion', 'jp');

    // The groups stay where they were. Choosing a region marks it; it does
    // not lift it over the one somebody is comparing it against.
    expect($game->refresh()->media_region)->toBe('jp')
        ->and($game->load('media')->artwork(MediaKind::Cover)->id)->toBe($japan->id)
        ->and(array_column($component->instance()->galleryByRegion, 'label'))->toBe(['Europe', 'Japan', 'No region'])
        ->and(array_column($component->instance()->galleryByRegion, 'selected'))->toBe([false, true, false]);

    // And back to whatever Settings says.
    $component->call('useRegion', null);

    expect($game->refresh()->media_region)->toBeNull()
        ->and($game->load('media')->artwork(MediaKind::Cover)->id)->toBe($europe->id);
});

it('offers only the regions it does not already hold, and queues one by name', function () {
    Queue::fake();

    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Final Fantasy IX', 'slug' => 'ff9']);
    Media::factory()->for($game)->ofType('box-2D', 'eu')->create();

    $component = Livewire::test('games.show', ['game' => $game])->call('selectTab', 'artwork');

    // A button that would re-download what is already on the page is not a
    // choice worth offering.
    expect($component->instance()->fetchableRegions)->not->toHaveKey('eu')
        ->and($component->instance()->fetchableRegions)->toHaveKey('jp');

    $component->set('fetchRegion', 'jp')->call('fetchRegionMedia');

    Queue::assertPushed(ScrapeGameMedia::class, fn ($job) => $job->gameId === $game->id && $job->region === 'jp');

    // A region nobody has heard of never reaches the queue.
    $component->call('fetchMedia', 'zz');

    Queue::assertPushed(ScrapeGameMedia::class, fn ($job) => $job->region === 'jp');
    Queue::assertNotPushed(ScrapeGameMedia::class, fn ($job) => $job->region === 'zz');
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
        ->set('enabled.video', true)
        ->set('enabled.box-2D', false)
        ->set('autoQueue', false)
        ->call('save');

    expect(MediaTypes::enabled())->toBe(['video'])
        ->and(AppSetting::enabled(AppSetting::AUTO_QUEUE_MEDIA_SCRAPE))->toBeFalse();
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

    foreach ([route('consoles.index'), route('games.index'), route('consoles.games', ['console' => 'snes']), route('media.edit')] as $url) {
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

    $this->get(route('games.show', $game->routeParameters()))
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
    $this->get(route('games.show', $game->routeParameters()))->assertOk()->assertSee('Try identifying again');
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
    $this->get(route('games.show', $game->routeParameters()))->assertOk()->assertSee('No media types are switched on');
});

it('waits for artwork to arrive, then stops', function () {
    Queue::fake();
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);

    $component = Livewire::test('games.show', ['game' => $game])
        ->assertSee('Fetch artwork')
        ->call('fetchMedia');

    // A string, so there is no count that reads as "not waiting": assertNotSet
    // compares loosely and 0 == null in PHP.
    $component->assertSet('fetchingFrom', '0:0')->assertSee('Fetching artwork');
    Queue::assertPushed(ScrapeGameMedia::class);

    $component->call('checkMedia')->assertSee('Fetching artwork');

    Media::factory()->for($game)->create();

    $component->call('checkMedia')->assertDontSee('Fetching artwork');
});

it('notices artwork that was replaced rather than added', function () {
    Queue::fake();
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);
    $old = Media::factory()->for($game)->ofType('box-2D', 'eu')->create();

    $component = Livewire::test('games.show', ['game' => $game])->call('fetchMedia');

    // What a scrape after a region change does: one row out, one row in. The
    // count is where it started, and a poll watching only that would wait out
    // its two minutes while the new cover sat there unshown.
    Media::factory()->for($game)->ofType('box-2D', 'jp')->create();
    $old->delete();

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

it('lists one console on its own shelf, and 404s on a key config does not carry', function () {
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Vagrant Story', 'slug' => 'vagrant']);

    $this->get(route('consoles.games', ['console' => 'snes']))
        ->assertOk()
        ->assertSee('Super Nintendo')
        ->assertSee('Super Mario World')
        ->assertDontSee('Vagrant Story');

    // The console is the route rather than a control, so the filter that would
    // change it is not offered.
    $this->get(route('consoles.games', ['console' => 'snes']))->assertDontSee('All consoles');

    // Livewire fills any public property named after a route parameter, so a
    // filter called $console would come back as /consoles/snes?console=snes.
    Livewire::test('games.index', ['console' => 'snes'])->assertSet('consoleFilter', '');

    $this->get('/consoles/notaconsole')->assertNotFound();
});

it('addresses a game under its console, by slug', function () {
    $game = Game::factory()->forConsole('snes')->matched()->create(['title' => 'Aladdin', 'slug' => 'aladdin']);

    expect(route('games.show', $game->routeParameters()))->toEndWith('/consoles/snes/aladdin');

    $this->get('/consoles/snes/aladdin')->assertOk()->assertSee('Aladdin');
});

it('tells apart two consoles that each hold a game of the same name', function () {
    // Slugs are unique per console and nowhere else, which is the whole reason
    // the console is in the address.
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Aladdin (SNES)', 'slug' => 'aladdin']);
    Game::factory()->forConsole('megadrive')->matched()->create(['title' => 'Aladdin (Mega Drive)', 'slug' => 'aladdin']);

    $this->get('/consoles/megadrive/aladdin')->assertOk()->assertSee('Aladdin (Mega Drive)')->assertDontSee('Aladdin (SNES)');
    $this->get('/consoles/snes/aladdin')->assertOk()->assertSee('Aladdin (SNES)');
});

it('does not find a game under a console it is not on', function () {
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Aladdin', 'slug' => 'aladdin']);

    $this->get('/consoles/nes/aladdin')->assertNotFound();
});

it('sends the old addresses to the new ones', function () {
    $game = Game::factory()->forConsole('snes')->matched()->create(['title' => 'Aladdin', 'slug' => 'aladdin']);

    $this->get('/games/'.$game->id)->assertMovedPermanently()->assertRedirect('/consoles/snes/aladdin');
    $this->get('/snes/games')->assertMovedPermanently()->assertRedirect('/consoles/snes');
});

it('counts identified games beside each console in the sidebar', function () {
    ConsoleSourceFolder::add(new Console('snes'));
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'One', 'slug' => 'one']);
    Game::factory()->forConsole('snes')->create(['title' => 'Unknown', 'slug' => 'unknown']);

    $html = $this->get(route('consoles.index'))->assertOk()->getContent();

    expect($html)->toMatch('/href="'.preg_quote(route('consoles.games', ['console' => 'snes']), '/').'".*?Super Nintendo<\/span>\s*<span[^>]*>1<\/span>/s');
});

it('lights the game\'s console in the sidebar, and not Games', function () {
    ConsoleSourceFolder::add(new Console('snes'));
    ConsoleSourceFolder::add(new Console('nes'));
    $game = Game::factory()->forConsole('snes')->matched()->create(['title' => 'Aladdin', 'slug' => 'aladdin']);

    $html = $this->get(route('games.show', $game->routeParameters()))->assertOk()->getContent();

    // The console's link carries the rail; its neighbour does not.
    expect($html)->toMatch('/href="'.preg_quote(route('consoles.games', ['console' => 'snes']), '/').'"[^>]*shadow-rail/s')
        ->and($html)->not->toMatch('/href="'.preg_quote(route('consoles.games', ['console' => 'nes']), '/').'"[^>]*shadow-rail/s')
        // The attribute, not the class: Flux spells data-current:… into every
        // item's classes whether it is current or not.
        ->and($html)->not->toMatch('/href="'.preg_quote(route('games.index'), '/').'" data-current="data-current"/');

    // And on the all-games list, Games is the one that is lit.
    expect($this->get(route('games.index'))->getContent())
        ->toMatch('/href="'.preg_quote(route('games.index'), '/').'" data-current="data-current"/');
});

it('shows the way back up the library over a game', function () {
    $game = Game::factory()->forConsole('snes')->matched()->create(['title' => 'Aladdin', 'slug' => 'aladdin']);

    $this->get(route('games.show', $game->routeParameters()))
        ->assertOk()
        ->assertSeeInOrder([
            'aria-label="Breadcrumb"',
            'href="'.route('consoles.index').'"', 'Consoles',
            'href="'.route('consoles.games', ['console' => 'snes']).'"', 'Super Nintendo',
            'aria-current="page"', 'Aladdin',
        ], escape: false);
});

it('remembers whether the library is drawn as covers or as a list', function () {
    $game = Game::factory()->forConsole('snes')->unmatched()->create(['title' => 'Unknown Disc', 'slug' => 'unknown']);
    GameFile::factory()->for($game)->create(['path' => 'snes/unknown.sfc', 'filename' => 'unknown.sfc', 'size_bytes' => 3145728]);

    // Cards by default. Achievements is a column header the table has and a
    // card has not — genre used to stand in for this, and now it is a line
    // under the title rather than a column, so it says nothing about the mode.
    Livewire::test('games.index')
        ->assertSee('Unidentified')
        ->assertDontSee('Achievements')
        ->call('setView', 'table')
        ->assertSee('Achievements');

    expect(AppSetting::get(AppSetting::UI_GAMES_VIEW))->toBe('table');

    // The memo outlives the component, and the point is that the next visit
    // reads the row back.
    AppSetting::flush();

    Livewire::test('games.index')->assertSee('Achievements');
    Livewire::test('games.index', ['console' => 'snes'])->assertSee('Achievements');

    // Anything else leaves it alone rather than drawing nothing at all.
    Livewire::test('games.index')->call('setView', 'mosaic')->assertSee('Achievements');

    // A link in the other mode still overrides what was remembered. Last,
    // because withQueryParams sticks to every Livewire::test after it.
    Livewire::withQueryParams(['view' => 'cards'])->test('games.index')->assertDontSee('Achievements');
});

it('draws every cover in the console\'s own frame, art or not', function () {
    // One frame for every card, the console's own, whatever shape a scan the
    // provider sent; art or none, the shelf is one shape throughout.
    $identified = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Tomb Raider', 'slug' => 'tomb-raider']);
    Media::factory()->for($identified)->ofType('box-2D', 'eu')->create();
    Game::factory()->forConsole('psx')->create(['title' => 'Tomb Raider (Europe)', 'slug' => 'tomb-raider-europe']);

    $html = Livewire::test('games.index', ['console' => 'psx'])->html();

    // PS1's 7/6 at 280 high: its jewel cases are a touch wider than tall.
    expect(substr_count($html, 'aspect-ratio: 327 / 280'))->toBe(2)
        ->and(substr_count($html, 'max-width: 349px'))->toBe(2)
        ->and($html)->toContain('object-contain');
});

it('sizes an empty slot from the console it belongs to', function () {
    // Real art supplies its own width; a placeholder has none, so the
    // console's ratio stands in and the slot holds a cover's worth of space.
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Vagrant Story', 'slug' => 'vagrant']);

    // 2/3 and 5/7 of the same 280px.
    Livewire::test('games.index', ['console' => 'snes'])->assertSee('aspect-ratio: 187 / 280', escape: false);
    Livewire::test('games.index', ['console' => 'psx'])->assertSee('aspect-ratio: 327 / 280', escape: false);
});

it('says how many files an export has written, not how many jobs it queued', function () {
    ExportProgress::advance('ps2', 'cfg', 12, 19);

    Livewire::test('system-activity')
        ->assertSeeInOrder(['Toolbox', '12', '/19'])
        ->assertDontSee('Exporting');

    ExportProgress::finish('ps2', 'cfg');

    // The row stays, as every quiet one does; only the count goes.
    Livewire::test('system-activity')
        ->assertSee('Toolbox')
        ->assertDontSee('/19');
});

it('runs the toolbox on a queue of its own, not behind the artwork', function () {
    Queue::fake();

    WriteConsoleExports::dispatch('ps2', 'art');
    InspectGameFile::dispatch(GameFile::factory()->create()->id);

    Queue::assertPushedOn('toolbox', WriteConsoleExports::class);
    Queue::assertPushedOn('toolbox', InspectGameFile::class);
});

it('counts a queued export on the Toolbox row, not on Artwork', function () {
    DB::table('jobs')->insert([
        'queue' => 'toolbox',
        'payload' => '{}',
        'attempts' => 0,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);
    SystemActivity::forget();

    $activity = SystemActivity::current();

    expect($activity->queues['toolbox']->remaining())->toBe(1)
        ->and($activity->queues['artwork']->remaining())->toBe(0);
});

/**
 * The card counts what is on the disk, not what is in the database.
 *
 * A drive filled over SMB says nothing to the database until a scan runs, and
 * a card claiming the folder is empty is the one number somebody checks
 * against what they can see in Finder.
 */
it('counts the files on disk rather than the rows in the database', function () {
    File::ensureDirectoryExists($this->root.'/ps2');

    foreach (['One.iso', 'Two.iso', 'Three.iso'] as $filename) {
        File::put($this->root.'/ps2/'.$filename, 'x');
    }

    ConsoleSourceFolder::add(new Console('ps2'));

    // A row for a game nothing on this drive accounts for. The count is the
    // disk's answer, so it says three either way.
    Game::factory()->create(['console' => 'ps2', 'title' => 'Not on this drive']);

    Livewire::test('consoles.index')->assertSee('3 files');
});

it('counts the same files the scanner would', function () {
    foreach (['DVD', 'ART', 'CFG'] as $directory) {
        File::ensureDirectoryExists($this->root.'/ps2/'.$directory);
    }

    File::put($this->root.'/ps2/DVD/Game.iso', 'x');
    // The layout's furniture, and the console's own exclusion. Neither is a
    // game, and counting them is how eleven titles become a hundred.
    File::put($this->root.'/ps2/ART/Game_COV.jpg', 'x');
    File::put($this->root.'/ps2/CFG/Game.cfg', 'x');
    File::put($this->root.'/ps2/DVD/games.bin', 'x');

    ConsoleSourceFolder::add(new Console('ps2'), null, 'opl');

    Livewire::test('consoles.index')->assertSee('1 file');
});

it('does not count a bios dump as a game', function () {
    File::ensureDirectoryExists($this->root.'/ps2');

    File::put($this->root.'/ps2/Game.iso', 'x');
    // PS2 lists .bin under both file_extensions and bios_extensions. The
    // scanner resolves that in favour of the game, and so does the card.
    File::put($this->root.'/ps2/scph39001.bin', 'x');

    ConsoleSourceFolder::add(new Console('ps2'));

    Livewire::test('consoles.index')->assertSee('2 files');
});

it('never reads the disk to draw the page', function () {
    File::ensureDirectoryExists($this->root.'/ps2');
    File::put($this->root.'/ps2/One.iso', 'x');

    ConsoleSourceFolder::add(new Console('ps2'));

    Livewire::test('consoles.index')->assertSee('1 file');

    File::put($this->root.'/ps2/Two.iso', 'x');

    // Still the stored count: the page reads what MeasureLibrary left and
    // never walks a folder itself, however often it renders.
    Livewire::test('consoles.index')->assertSee('1 file');

    FolderCounts::recount(new Console('ps2'));

    Livewire::test('consoles.index')->assertSee('2 files');
});

it('counts the folder again once a scan has walked it', function () {
    File::ensureDirectoryExists($this->root.'/ps2');
    File::put($this->root.'/ps2/One.iso', 'x');

    ConsoleSourceFolder::add(new Console('ps2'));

    Livewire::test('consoles.index')->assertSee('1 file');

    File::put($this->root.'/ps2/Two.iso', 'x');

    // Faked so the scan's follow-up work — a provider lookup per new game —
    // stays out of a test that is only about the count. The recount itself
    // runs, since it is the thing under test.
    Queue::fake()->except(MeasureLibrary::class);

    (new ScanConsoleFolder('ps2'))->handle(app(LibraryScanner::class));

    Livewire::test('consoles.index')->assertSee('2 files');
});

it('says nothing rather than throwing when the drive is not mounted', function () {
    ConsoleSourceFolder::add(new Console('ps2'));

    File::deleteDirectory($this->root.'/ps2');

    // A card that renders is worth more than a page that does not. Finding out
    // the mount has gone is the scan's job, not the list's.
    Livewire::test('consoles.index')->assertSee('0 files');

    it('caps a cover by its console rather than stretching it to the column', function () {
        $game = Game::factory()->forConsole('gba')->matched()->create(['title' => 'Metroid Fusion', 'slug' => 'fusion']);
        Media::factory()->for($game)->ofType('box-2D', 'eu')->create();

        // The one knob that makes one console's shelf smaller than another's, and
        // a ceiling rather than a height: a narrow column shrinks the cover
        // further instead of putting bars around it.
        Livewire::test('games.index', ['console' => 'gba'])
            ->assertSee('max-height: 240px', escape: false)
            ->assertDontSee('height: 240px;', escape: false);
    });

    it('caps how many covers a row can hold, at every width', function () {
        Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);

        // The cap is the point: covers stretch to their column, so without one a
        // wide screen draws either postage stamps or posters.
        Livewire::test('games.index', ['console' => 'snes'])
            ->assertSee('grid-cols-2', escape: false)
            ->assertSee('sm:grid-cols-3', escape: false)
            ->assertSee('lg:grid-cols-4', escape: false)
            ->assertSee('xl:grid-cols-5', escape: false)
            ->assertSee('2xl:grid-cols-6', escape: false);
    });

});

/*
 * The provider's rating, which is what makes a shelf of three thousand games
 * navigable: sorted by it, filtered by it, and readable without opening a game.
 */

it('sorts by rating with the unrated last, not first', function () {
    Game::factory()->forConsole('snes')->matched()->rated(64)->create(['title' => 'Middling', 'slug' => 'mid']);
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Unrated', 'slug' => 'unrated']);
    Game::factory()->forConsole('snes')->matched()->rated(95)->create(['title' => 'Excellent', 'slug' => 'excellent']);

    // Descending alone puts NULL at the top on MariaDB, which reads as the
    // best games being the ones nobody has an opinion about.
    Livewire::test('games.index')
        ->set('sort', 'rating')
        ->assertSeeInOrder(['Excellent', 'Middling', 'Unrated']);
});

it('sorts by title by default, whatever the ratings say', function () {
    Game::factory()->forConsole('snes')->matched()->rated(95)->create(['title' => 'Zeta', 'slug' => 'zeta']);
    Game::factory()->forConsole('snes')->matched()->rated(20)->create(['title' => 'Alpha', 'slug' => 'alpha']);

    Livewire::test('games.index')->assertSeeInOrder(['Alpha', 'Zeta']);
});

it('filters out everything below the rating asked for', function () {
    Game::factory()->forConsole('snes')->matched()->rated(95)->create(['title' => 'Excellent', 'slug' => 'excellent']);
    Game::factory()->forConsole('snes')->matched()->rated(64)->create(['title' => 'Middling', 'slug' => 'mid']);
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Unrated', 'slug' => 'unrated']);

    Livewire::test('games.index')
        ->set('minRating', '80')
        ->assertSee('Excellent')
        ->assertDontSee('Middling')
        // A game with no rating is not a game rated below eighty, but it is
        // not one the filter was asked for either.
        ->assertDontSee('Unrated');
});

it('lists only the games still waiting to be identified, or only the identified', function () {
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Known Quantity', 'slug' => 'known']);
    Game::factory()->forConsole('snes')->create(['title' => 'Fresh From Disk', 'slug' => 'fresh', 'status' => GameStatus::Placeholder]);
    Game::factory()->forConsole('snes')->unmatched()->create(['title' => 'Nowhere To Be Found', 'slug' => 'nowhere']);

    // Needed takes in both: never looked up, and looked up with no match.
    Livewire::test('games.index', ['console' => 'snes'])
        ->assertSee('Needs identifying')
        ->set('identified', 'needed')
        ->assertSee('Fresh From Disk')
        ->assertSee('Nowhere To Be Found')
        ->assertDontSee('Known Quantity')
        ->set('identified', 'identified')
        ->assertSee('Known Quantity')
        ->assertDontSee('Fresh From Disk')
        ->assertDontSee('Nowhere To Be Found')
        ->call('clear')
        ->assertSet('identified', '');
});

it('clears the rating filter but keeps the sort', function () {
    Game::factory()->forConsole('snes')->matched()->rated(95)->create();

    // Clear is about which games are shown. How they are ordered is a way of
    // reading the library, and somebody who chose it meant it to stick.
    Livewire::test('games.index')
        ->set('sort', 'rating')
        ->set('minRating', '80')
        ->set('query', 'nothing')
        ->call('clear')
        ->assertSet('minRating', '')
        ->assertSet('query', '')
        ->assertSet('sort', 'rating');
});

it('shows the rating on the shelf and on the game', function () {
    $game = Game::factory()->forConsole('snes')->matched()->rated(84)->create([
        'title' => 'Super Mario World', 'slug' => 'smw',
    ]);

    Livewire::test('games.index')->assertSee('84');

    $this->get(route('games.show', $game->routeParameters()))->assertOk()->assertSee('84 / 100');
});

it('says nothing about the rating of a game that has none', function () {
    $game = Game::factory()->forConsole('snes')->matched()->create([
        'title' => 'Super Mario World', 'slug' => 'smw',
    ]);

    $this->get(route('games.show', $game->routeParameters()))
        ->assertOk()
        ->assertDontSee('/ 100')
        // The grid cell holds a dash, never a zero or a band colour.
        ->assertDontSee('out of 100 by ScreenScraper');
});

it('states the rating once on the game page, not twice', function () {
    $game = Game::factory()->forConsole('snes')->matched()->rated(84)->create([
        'title' => 'Super Mario World', 'slug' => 'smw',
    ]);

    // A page that says the same number twice leaves the reader working out
    // which of the two is the other one. The details grid is the one place.
    $page = $this->get(route('games.show', $game->routeParameters()))->assertOk();

    expect(substr_count($page->getContent(), '84 / 100'))->toBe(1);

    $details = Livewire::test('games.show', ['game' => $game])->instance()->details;

    expect(array_count_values(array_column($details, 'key')))->toHaveKey('rating', 1);
});

it('lays the game\'s facts out in the details grid, dashing what is missing', function () {
    $game = Game::factory()->forConsole('snes')->matched()->create([
        'title' => 'Super Mario World', 'slug' => 'smw',
        'developer' => 'Nintendo EAD', 'publisher' => 'Nintendo', 'genre' => 'Platform', 'players' => null,
    ]);

    $details = Livewire::test('games.show', ['game' => $game])->instance()->details;

    expect(array_column($details, 'key'))
        ->toBe(['region', 'console', 'released', 'rating', 'developer', 'publisher', 'genre', 'players'])
        ->and(array_column($details, 'value', 'key'))->toMatchArray([
            'developer' => 'Nintendo EAD',
            'publisher' => 'Nintendo',
            'genre' => 'Platform',
            'players' => '—',
            'rating' => '—',
        ]);

    $this->get(route('games.show', $game->routeParameters()))
        ->assertOk()
        ->assertSeeInOrder(['Developer', 'Nintendo EAD', 'Publisher', 'Nintendo', 'Genre', 'Platform']);
});

it('does not add a query per row to show ratings', function () {
    // flushQueryLog between measurements, or the second reading is the first
    // one plus the second: the log accumulates and disableQueryLog() does not
    // empty it.
    $count = function (int $games): int {
        Game::query()->delete();
        Game::factory()->forConsole('snes')->matched()->rated()->count($games)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test('games.index')->set('sort', 'rating');
        $queries = count(DB::getRawQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // Once before measuring: the first render of the suite pays for a session
    // and the settings memo, and that is not what is being counted.
    $count(1);

    // Measured against itself rather than against a number. What matters is
    // that the page costs the same whatever is on it, and a fixed budget has
    // to be raised every time a filter's list is added — which is exactly how
    // a real per-row query gets waved through.
    //
    // The rating is a column on games, so it rides along with the row it
    // belongs to. Eighteen more selects would mean it had become a relation.
    expect($count(20))->toBe($count(2));
});

it('colours the shelf badge by how good the game is', function () {
    Game::factory()->forConsole('snes')->matched()->rated(95)->create(['title' => 'Excellent', 'slug' => 'excellent']);
    Game::factory()->forConsole('snes')->matched()->rated(22)->create(['title' => 'Poor', 'slug' => 'poor']);

    // Inline styles, because the colour is picked at runtime and Tailwind only
    // generates the classes it saw in the source. If these ever become classes
    // this test is what says the badge went blank.
    Livewire::test('games.index')
        ->assertSee('background-color: var(--color-accent);', escape: false)
        ->assertSee('background-color: var(--color-danger);', escape: false);
});

it('gives the game page the same band as the shelf', function () {
    $game = Game::factory()->forConsole('snes')->matched()->rated(45)->create([
        'title' => 'Middling', 'slug' => 'middling',
    ]);

    // A game must not change verdict on the way from the shelf to its page.
    $this->get(route('games.show', $game->routeParameters()))
        ->assertOk()
        ->assertSee('var(--color-warn)', escape: false);
});

/*
 * Asking for a rating by hand, from a console's menu or a game's.
 *
 * The backfill command reaches a whole library once; these are the two places
 * somebody asks about a shelf or a single game after the fact, which is the
 * only way to pick up votes cast since the match.
 */

it('fetches ratings for a whole console, skipping the games that already have one', function () {
    Queue::fake();
    ConsoleSourceFolder::add(new Console('snes'));

    $bare = Game::factory()->forConsole('snes')->matched(101)->create();
    Game::factory()->forConsole('snes')->matched(102)->rated(80)->create();

    // A placeholder has no provider id, and the rating comes back by one.
    Game::factory()->forConsole('snes')->create(['screenscraper_id' => null, 'status' => GameStatus::Placeholder]);

    // And another console's game is not this console's business.
    Game::factory()->forConsole('nes')->matched(103)->create();

    Livewire::test('consoles.index')->call('fetchRatings', 'snes');

    Queue::assertPushed(RateGame::class, 1);
    Queue::assertPushed(RateGame::class, fn ($job) => $job->gameId === $bare->id && $job->force === false);
});

it('re-fetches every rating on a console when asked to start over', function () {
    Queue::fake();
    ConsoleSourceFolder::add(new Console('snes'));

    Game::factory()->forConsole('snes')->matched(101)->create();
    Game::factory()->forConsole('snes')->matched(102)->rated(80)->create();

    Livewire::test('consoles.index')->call('fetchRatings', 'snes', true);

    // Forced, or the job's own guard would drop the one that already has a
    // rating — which is the very game somebody asking again means.
    Queue::assertPushed(RateGame::class, 2);
    Queue::assertPushed(RateGame::class, fn ($job) => $job->force === true);
});

it('says so rather than queueing nothing when a console has every rating already', function () {
    // Added before the fake: adding a console queues a file count, which is
    // not the queueing this test is about.
    ConsoleSourceFolder::add(new Console('snes'));
    Queue::fake();
    Game::factory()->forConsole('snes')->matched(101)->rated(80)->create();

    Livewire::test('consoles.index')
        ->call('fetchRatings', 'snes')
        ->assertDispatched('toast-show');

    Queue::assertNothingPushed();
});

it('needs no media types switched on to fetch a rating', function () {
    Queue::fake();
    ConsoleSourceFolder::add(new Console('snes'));
    Game::factory()->forConsole('snes')->matched(101)->create();

    // The artwork actions refuse here. A rating rides along in the same answer
    // and there is nothing to choose, so this one has no such gate.
    AppSetting::put(AppSetting::MEDIA_TYPES, []);

    Livewire::test('consoles.index')->call('fetchRatings', 'snes');

    Queue::assertPushed(RateGame::class, 1);
});

it('offers a rating fetch on the game page and forces it', function () {
    Queue::fake();

    $game = Game::factory()->forConsole('psx')->matched(19256)->rated(70)->create([
        'title' => 'Tekken 3', 'slug' => 'tekken-3',
    ]);

    Livewire::test('games.show', ['game' => $game])
        // The label says which of the two this is; the action is the same.
        ->assertSee('Fetch rating again')
        ->call('fetchRating');

    Queue::assertPushed(RateGame::class, fn ($job) => $job->gameId === $game->id && $job->force === true);
});

it('refuses a rating for a game nobody has identified', function () {
    Queue::fake();

    $game = Game::factory()->forConsole('psx')->create(['title' => 'Mystery', 'slug' => 'mystery']);

    $this->get(route('games.show', $game->routeParameters()))
        ->assertOk()
        ->assertSee('Fetch rating')
        ->assertSee('Identify the game first — the rating comes back by provider id.');

    Livewire::test('games.show', ['game' => $game])->call('fetchRating');

    Queue::assertNothingPushed();
});

it('waits for a rating to arrive, then stops', function () {
    Queue::fake();

    $game = Game::factory()->forConsole('psx')->matched(19256)->create([
        'title' => 'Tekken 3', 'slug' => 'tekken-3',
    ]);

    $component = Livewire::test('games.show', ['game' => $game])
        ->assertSee('Fetch rating')
        ->call('fetchRating');

    // A string, so an unrated game does not read as "not waiting": null means
    // idle here, the same as it does for artwork.
    $component->assertSet('ratingFrom', '')->assertSee('Fetching the rating');

    $component->call('checkRating')->assertSee('Fetching the rating');

    $game->update(['rating' => 90]);

    $component->call('checkRating')->assertDontSee('Fetching the rating');
});

it('gives up waiting when the provider holds no rating', function () {
    Queue::fake();

    $game = Game::factory()->forConsole('psx')->matched(19256)->create([
        'title' => 'Obscure', 'slug' => 'obscure',
    ]);

    // Nothing is written for a game nobody has voted on, so there is no
    // arrival to notice — the same shape as artwork the provider does not hold.
    $component = Livewire::test('games.show', ['game' => $game])->call('fetchRating');

    $component->call('checkRating')->assertSee('Fetching the rating');

    $this->travel(3)->minutes();

    $component->call('checkRating')->assertDontSee('Fetching the rating');
});

/*
 * The list view's rows, which are two text lines tall with a square cover at
 * the left edge.
 */

it('puts a square cover at the left of a list row', function () {
    $game = Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);
    $cover = Media::factory()->for($game)->ofType('box-2D', 'eu')->create();

    // size-12 is the frame and object-contain is what keeps the art its own
    // shape inside it. Cropping a square out of box art cuts the title off.
    Livewire::withQueryParams(['view' => 'table'])->test('games.index')
        ->assertSee('size-12', escape: false)
        ->assertSee('object-contain', escape: false)
        ->assertSee(route('media.show', ['path' => $cover->path]), escape: false);
});

it('stands the console icon in for a list row with no cover', function () {
    Game::factory()->forConsole('snes')->create(['title' => 'Unknown Disc', 'slug' => 'unknown']);

    // The frame is there either way, or the rows either side of an unidentified
    // game would be a different height from it.
    Livewire::withQueryParams(['view' => 'table'])->test('games.index')
        ->assertSee('size-12', escape: false)
        ->assertSee((new Console('snes'))->fileIcon, escape: false);
});

it('moves the console and the genre under the title rather than into columns', function () {
    Game::factory()->forConsole('snes')->matched()->create([
        'title' => 'Super Mario World', 'slug' => 'smw', 'genre' => 'Platform',
    ]);

    // Both are still on the page, as the row's second line. Neither is a column
    // header any more, which is the width the achievement bar was given.
    $component = Livewire::withQueryParams(['view' => 'table'])->test('games.index')
        ->assertSee('Super Nintendo')
        ->assertSee('Platform');

    expect($component->html())->not->toContain('font-medium">Genre<');
});

it('keeps the second line of a list row even with nothing to put on it', function () {
    // A console's own shelf drops the console from the line, and an
    // unidentified game has no genre — so the line has nothing at all. It is
    // still drawn, or this row would be shorter than the ones around it.
    Game::factory()->forConsole('snes')->create(['title' => 'Unknown Disc', 'slug' => 'unknown']);

    Livewire::withQueryParams(['view' => 'table'])->test('games.index', ['console' => 'snes'])
        ->assertSee('Unknown Disc')
        ->assertSee('text-xs text-fg-dim', escape: false);
});

/*
 * The list row's menu, which replaced the single button that showed Identify
 * or Artwork and never both.
 */

it('offers every per-game action from a list row', function () {
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    $game = Game::factory()->forConsole('psx')->matched(19256)->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);
    GameFile::factory()->for($game)->create(['path' => 'psx/t.bin', 'filename' => 't.bin', 'role' => FileRole::Track]);

    // The same three the console's own menu runs over a whole shelf, aimed at
    // one game. The old row could only ever show one of them.
    Livewire::withQueryParams(['view' => 'table'])->test('games.index')
        ->assertSee('Identify game')
        ->assertSee('Fetch artwork')
        ->assertSee('Fetch rating')
        ->assertSee('Open game');
});

it('says why a list row cannot run an action rather than hiding it', function () {
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    // A placeholder can be identified but has no provider id, so neither
    // artwork nor a rating can be fetched for it yet.
    $game = Game::factory()->forConsole('psx')->create(['title' => 'Unknown', 'slug' => 'unknown']);
    GameFile::factory()->for($game)->create(['path' => 'psx/u.bin', 'filename' => 'u.bin', 'role' => FileRole::Track]);

    Livewire::withQueryParams(['view' => 'table'])->test('games.index')
        ->assertSee('Identify the game first — artwork is fetched by provider id.')
        ->assertSee('Identify the game first — the rating comes back by provider id.');
});

it('labels a list row by what the game already holds', function () {
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    $game = Game::factory()->forConsole('psx')->matched(19256)->rated(70)->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);
    Media::factory()->for($game)->ofType('box-2D', 'eu')->create();

    Livewire::withQueryParams(['view' => 'table'])->test('games.index')
        ->assertSee('Fetch artwork again')
        ->assertSee('Fetch rating again');
});

it('fetches a rating for one game from the list, forced', function () {
    Queue::fake();

    $game = Game::factory()->forConsole('psx')->matched(19256)->rated(70)->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);

    Livewire::test('games.index')->call('fetchRating', $game->id);

    // Unforced, the job would drop a game that already has a rating — which is
    // the very game somebody picking this item most likely meant.
    Queue::assertPushed(RateGame::class, fn ($job) => $job->gameId === $game->id && $job->force === true);
});

it('refuses a rating from the list for a game nobody has identified', function () {
    Queue::fake();

    $game = Game::factory()->forConsole('psx')->create(['title' => 'Unknown', 'slug' => 'unknown']);

    Livewire::test('games.index')
        ->call('fetchRating', $game->id)
        ->assertDispatched('toast-show');

    Queue::assertNothingPushed();
});

/*
 * Year and Players, and the widths that keep the columns still.
 */

it('shows the year and the player count in the list', function () {
    Game::factory()->forConsole('psx')->matched()->create([
        'title' => 'Tekken 3', 'slug' => 'tekken-3',
        'release_date' => '1998-03-26', 'players' => '1-2',
    ]);

    Livewire::withQueryParams(['view' => 'table'])->test('games.index')
        ->assertSee('Year')
        ->assertSee('Players')
        ->assertSee('1998')
        ->assertSee('1-2')
        // The date is a column of years, not of dates: the rest of the string
        // is a different shape from one submission to the next.
        ->assertDontSee('1998-03-26');
});

it('takes the year off whatever shape the provider\'s date arrived in', function () {
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Bare Year', 'slug' => 'bare', 'release_date' => '1995']);
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Full Date', 'slug' => 'full', 'release_date' => '1996-11-04']);

    Livewire::withQueryParams(['view' => 'table'])->test('games.index')
        ->assertSee('1995')
        ->assertSee('1996');
});

it('leaves the year and players blank rather than empty when unknown', function () {
    Game::factory()->forConsole('psx')->create([
        'title' => 'Unknown', 'slug' => 'unknown', 'release_date' => null, 'players' => null,
    ]);

    // A placeholder knows neither, and a cell with nothing in it reads as a
    // rendering fault rather than as an answer.
    $html = Livewire::withQueryParams(['view' => 'table'])->test('games.index')->html();

    expect(substr_count($html, '—'))->toBeGreaterThanOrEqual(2);
});

it('pins every list column but the title', function () {
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);

    // table-fixed is what makes the widths hold and what makes truncate on the
    // title work at all — without it the column follows its longest cell and
    // the same shelf is a different shape on page two.
    Livewire::withQueryParams(['view' => 'table'])->test('games.index')
        ->assertSee('table-fixed', escape: false)
        ->assertSee('<colgroup>', escape: false);
});

/*
 * The console shelf's hero, which borrows its key art from the games on the
 * shelf because nothing ships artwork per console.
 */

it('carries key art from the console\'s own games behind the shelf header', function () {
    $game = Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);
    $art = Media::factory()->for($game)->ofType('fanart', 'us')->create(['size_bytes' => 400_000]);

    // The same fade as the game page's hero: down into the page's own ground,
    // not sideways off the text.
    Livewire::test('games.index', ['console' => 'snes'])
        ->assertSee('Super Nintendo')
        ->assertSee(route('media.show', ['path' => $art->path]), escape: false)
        ->assertSee('hero-fade-y', escape: false)
        ->assertDontSee('hero-fade-x', escape: false);
});

it('will not blow up a screenshot or a thumbnail behind the header', function () {
    $game = Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);

    // An in-game grab is filed as a backdrop beside real wallpaper, and a
    // piece of key art can still arrive thumbnail sized.
    $shot = Media::factory()->for($game)->ofType('ss', 'us')->create(['size_bytes' => 400_000]);
    $tiny = Media::factory()->for($game)->ofType('fanart', 'us')->create(['size_bytes' => 4_000]);

    $html = Livewire::test('games.index', ['console' => 'snes'])->html();

    expect($html)->not->toContain($shot->path)
        ->and($html)->not->toContain($tiny->path);
});

/** A rated game on the SNES shelf with one wallpaper-sized piece of key art. */
function shelfArt(int $rating, ?int $count = 1): array
{
    $game = Game::factory()->forConsole('snes')->matched()->rated($rating)->create(['title' => 'Rated '.$rating, 'slug' => 'rated-'.$rating]);

    return Media::factory()->for($game)->ofType('fanart', 'us')->count((int) $count)->create(['size_bytes' => 400_000])
        ->pluck('path')
        ->all();
}

it('rotates the backdrop through the six best-rated games\' art', function () {
    $best = collect([95, 90, 85, 80, 75, 70])->flatMap(function (int $rating): array {
        return shelfArt($rating);
    })->all();
    $worst = [...shelfArt(20), ...shelfArt(10)];

    $seen = collect(range(1, 40))->map(function (): ?string {
        return Livewire::test('games.index', ['console' => 'snes'])->get('heroArt');
    })->unique();

    // Always one of the six, never the two below them, and more than one of
    // them over forty visits — the chance of a single one forty times running
    // is a sixth to the thirty-ninth.
    expect($seen->diff($best))->toBeEmpty()
        ->and($seen->intersect($worst))->toBeEmpty()
        ->and($seen->count())->toBeGreaterThan(1);
});

it('counts a game once, however many wallpapers it holds', function () {
    $many = shelfArt(95, 7);
    $other = shelfArt(50);

    $seen = collect(range(1, 40))->map(function (): ?string {
        return Livewire::test('games.index', ['console' => 'snes'])->get('heroArt');
    })->unique();

    // The best game's first wallpaper, and the other game — not seven shots
    // of the same game crowding everything else out of the six.
    expect($seen->values()->sort()->values()->all())->toBe(collect([$many[0], $other[0]])->sort()->values()->all());
});

it('keeps the same backdrop while the shelf is filtered', function () {
    foreach ([95, 90, 85] as $rating) {
        shelfArt($rating);
    }

    $shelf = Livewire::test('games.index', ['console' => 'snes']);
    $picked = $shelf->get('heroArt');

    // Picked when the page loads, not each time it redraws: a backdrop that
    // jumped under a select would read as a page failing to load.
    $shelf->set('minRating', '80')->assertSet('heroArt', $picked)
        ->set('query', 'Rated')->assertSet('heroArt', $picked);
});

it('keeps the pool cached rather than asking again on each visit', function () {
    $first = shelfArt(95);

    Livewire::test('games.index', ['console' => 'snes'])->assertSet('heroArt', $first[0]);

    // Scraped since: not in the rotation until the cached pool lapses.
    shelfArt(99);

    Livewire::test('games.index', ['console' => 'snes'])->assertSet('heroArt', $first[0]);

    Cache::forget('shelf.backdrops.snes');

    $seen = collect(range(1, 30))->map(function (): ?string {
        return Livewire::test('games.index', ['console' => 'snes'])->get('heroArt');
    })->unique();

    expect($seen->count())->toBe(2);
});

it('keeps the header band when the shelf has no artwork at all', function () {
    Game::factory()->forConsole('snes')->create(['title' => 'Unknown Disc', 'slug' => 'unknown']);

    // Most of a new library. The band keeps its height and its ground rather
    // than collapsing back to the plain heading it used to be.
    Livewire::test('games.index', ['console' => 'snes'])
        ->assertSee('Super Nintendo')
        ->assertSee('min-h-[187px]', escape: false)
        ->assertSee('var(--color-raised)', escape: false);
});

it('runs the shelf hero to the page edges', function () {
    $game = Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);
    Media::factory()->for($game)->ofType('fanart', 'us')->create(['size_bytes' => 400_000]);

    // The page asks its layout for `bleed`, so flux:main is not rendered and
    // nothing here sits inside its padding. The band carries none, which is
    // what lets the art reach the window edges; the filters below carry their
    // own, which is what keeps them lined up with the console's name.
    $html = Livewire::test('games.index', ['console' => 'snes'])->html();

    expect($html)->toContain('lg:px-7.5')       // the bar, at the game page's offsets
        ->and($html)->toContain('lg:px-8');      // the bands below, carrying their own gutters
});

it('sets the console figures against its name on one row', function () {
    $matched = Game::factory()->forConsole('snes')->matched()->create(['title' => 'Identified One', 'slug' => 'one']);
    GameFile::factory()->for($matched)->create(['path' => 'snes/one.sfc', 'filename' => 'one.sfc', 'size_bytes' => 3_145_728]);

    // Name and maker on the left, figures hard right, both on the baseline —
    // one row is what the band is half its old height for.
    $html = Livewire::test('games.index', ['console' => 'snes'])->html();

    expect($html)->toContain('justify-between')
        ->and($html)->toContain('text-end');

    // Name first, then who made it and when under it, then the figures.
    Livewire::test('games.index', ['console' => 'snes'])
        ->assertSeeInOrder(['Super Nintendo', 'Nintendo · 1990', 'Games', 'On disk']);
});

it('counts the console in the shelf header', function () {
    $matched = Game::factory()->forConsole('snes')->matched()->create(['title' => 'Identified One', 'slug' => 'one']);
    GameFile::factory()->for($matched)->create(['path' => 'snes/one.sfc', 'filename' => 'one.sfc', 'size_bytes' => 3_145_728]);

    $placeholder = Game::factory()->forConsole('snes')->create(['title' => 'Unknown', 'slug' => 'unknown']);
    GameFile::factory()->for($placeholder)->create(['path' => 'snes/u.sfc', 'filename' => 'u.sfc', 'size_bytes' => 1_048_576]);

    // Another console's games are not this shelf's business.
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Elsewhere', 'slug' => 'elsewhere']);

    // One game, because the placeholder is not one yet; its file still counts
    // towards the size. No folder on disk in this test, so no files either.
    Livewire::test('games.index', ['console' => 'snes'])
        ->assertSee('Nintendo')
        ->assertSeeInOrder(['Games', '>1<', 'Files', '>0<', 'On disk', '4.0 MB'], escape: false);
});

it('counts a multi-disc game once in the shelf header', function () {
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Final Fantasy IX', 'slug' => 'ff9']);

    foreach (['a', 'b', 'c', 'd'] as $disc) {
        GameFile::factory()->for($game)->create([
            'path' => "psx/ff9-{$disc}.bin", 'filename' => "ff9-{$disc}.bin",
            'role' => FileRole::Track, 'size_bytes' => 1_048_576,
        ]);
    }

    // The files join multiplies a game by its tracks, so a four-disc game
    // would read as four games without count(distinct).
    Livewire::test('games.index', ['console' => 'psx'])
        ->assertSeeInOrder(['Games', '>1<', 'Files'], escape: false)
        ->assertSee('4.0 MB');
});

it('says nothing about achievements on a console with no sets', function () {
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'No Set', 'slug' => 'no-set']);

    // Nought out of nought would read as nobody having unlocked anything,
    // which is a different thing from there being nothing to unlock.
    //
    // Cards explicitly: the list view has an Achievements column header of its
    // own, and this is about the hero.
    Livewire::withQueryParams(['view' => 'cards'])->test('games.index', ['console' => 'snes'])
        ->assertDontSee('Achievements');
});

it('leaves the whole library without a console hero', function () {
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);

    // There is no one console to be specific about, so there is no backdrop to
    // borrow and nothing to count.
    Livewire::test('games.index')
        ->assertSee('Library')
        ->assertDontSee('On disk');
});

/*
 * The shelf hero's Actions menu, which runs the console's actions from the
 * console's own page rather than only from the list of all of them.
 */

it('offers the console actions from the shelf header', function () {
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);

    Livewire::test('games.index', ['console' => 'snes'])
        ->assertSee('Scan folder')
        ->assertSee('Fetch missing artwork')
        ->assertSee('Re-fetch all artwork')
        ->assertSee('Fetch missing ratings')
        ->assertSee('Re-fetch all ratings')
        ->assertSee('Manage console');
});

it('keeps the shelf actions off the whole library', function () {
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);

    // There is no one console for them to act on.
    Livewire::test('games.index')->assertDontSee('Scan folder');
});

it('scans this console from its own shelf', function () {
    Queue::fake();

    Livewire::test('games.index', ['console' => 'snes'])->call('scanConsole');

    Queue::assertPushed(ScanConsoleFolder::class, fn ($job) => $job->console === 'snes');
});

it('fetches artwork for this console from its own shelf', function () {
    Queue::fake();
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    $bare = Game::factory()->forConsole('snes')->matched(101)->create();
    $dressed = Game::factory()->forConsole('snes')->matched(102)->create();
    Media::factory()->for($dressed)->ofType('box-2D', 'eu')->create();

    // Another console's games are not this shelf's business, however the menu
    // was reached.
    Game::factory()->forConsole('psx')->matched(103)->create();

    Livewire::test('games.index', ['console' => 'snes'])->call('fetchConsoleMedia');

    Queue::assertPushed(ScrapeGameMedia::class, 1);
    Queue::assertPushed(ScrapeGameMedia::class, fn ($job) => $job->gameId === $bare->id);
});

it('re-fetches every rating on this console from its own shelf', function () {
    Queue::fake();

    Game::factory()->forConsole('snes')->matched(101)->create();
    Game::factory()->forConsole('snes')->matched(102)->rated(80)->create();

    Livewire::test('games.index', ['console' => 'snes'])->call('fetchConsoleRatings', true);

    Queue::assertPushed(RateGame::class, 2);
    Queue::assertPushed(RateGame::class, fn ($job) => $job->force === true);
});

it('says so from the shelf too when no media types are switched on', function () {
    Queue::fake();
    Game::factory()->forConsole('snes')->matched(101)->create();

    AppSetting::put(AppSetting::MEDIA_TYPES, []);

    Livewire::test('games.index', ['console' => 'snes'])
        ->call('fetchConsoleMedia')
        ->assertDispatched('toast-show');

    Queue::assertNothingPushed();
});

it('will not run a shelf action on a page fixed to no console', function () {
    Queue::fake();

    // Every one of them reads lockedTo rather than taking a console key, so
    // the whole library has nothing for them to act on and they do nothing.
    Livewire::test('games.index')
        ->call('scanConsole')
        ->call('fetchConsoleMedia')
        ->call('fetchConsoleRatings')
        ->call('writeConsoleExport', 'cfg');

    Queue::assertNothingPushed();
});

it('queues the OPL export from a PS2 shelf, on the toolbox queue', function () {
    Queue::fake();
    File::ensureDirectoryExists($this->root.'/ps2');
    ConsoleSourceFolder::add(new Console('ps2'), null, 'opl');
    Game::factory()->forConsole('ps2')->matched()->create(['title' => 'Tekken Tag Tournament', 'slug' => 'ttt']);

    Livewire::test('games.index', ['console' => 'ps2'])
        ->assertSee('Write OPL configs')
        ->call('writeConsoleExport', 'cfg')
        ->assertDispatched('toast-show');

    Queue::assertPushedOn('toolbox', WriteConsoleExports::class, function (WriteConsoleExports $job): bool {
        return $job->console === 'ps2' && $job->export === 'cfg';
    });
});

it('offers an export only where the console\'s toolbox writes one', function () {
    Queue::fake();
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);

    // The SNES has no loader files to write, so the item is not drawn — and the
    // handler refuses the name even when it is called directly, which is the
    // half that a hidden menu item does not cover.
    Livewire::test('games.index', ['console' => 'snes'])
        ->assertDontSee('Write OPL configs')
        ->call('writeConsoleExport', 'cfg');

    Queue::assertNothingPushed();
});

/*
 * What the shelf header says about the machine itself.
 */

it('shows the console at a size it can be read at', function () {
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);

    Livewire::test('games.index', ['console' => 'snes'])
        ->assertSee('size-20', escape: false)
        ->assertSee((new Console('snes'))->icon, escape: false);
});

it('says who made the console and when, under its name', function () {
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);

    Livewire::test('games.index', ['console' => 'psx'])->assertSee('Sony · 1994');
});

it('says the maker alone for an entry that was never a machine', function () {
    Game::factory()->forConsole('mame')->matched()->create(['title' => 'Some Arcade Game', 'slug' => 'arcade']);

    // MAME and the other front-ends cover many systems and were released as
    // none of them. A middot with nothing after it would read as a gap where a
    // year should be.
    Livewire::test('games.index', ['console' => 'mame'])
        ->assertSee('Various')
        ->assertDontSee('Various ·');
});

it('carries a release year for every console it lists', function () {
    // Null is an answer here, not a hole — but the key has to be present, or a
    // console file added later would silently read as one of the front-ends.
    foreach (array_keys(config('consoles')) as $key) {
        expect(config("consoles.{$key}"))->toHaveKey('released');
    }
});

it('reads the earliest name a system shipped under', function () {
    // The Famicom is 1983 and the NES 1985; the Mark III is 1985 and the
    // Master System 1986. The earliest is the one stored, so a system does not
    // change age with the region it is named for.
    expect((new Console('nes'))->released)->toBe(1983)
        ->and((new Console('mastersystem'))->released)->toBe(1985)
        ->and((new Console('mame'))->released)->toBeNull();
});

/*
 * Manage console, which is one link from either list straight into that
 * console's settings form.
 */

it('links a shelf to its own console settings', function () {
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);

    Livewire::test('games.index', ['console' => 'snes'])
        ->assertSee('Manage console')
        ->assertSee(route('console-config.edit', ['console' => 'snes']), escape: false);
});

it('offers the same link from the console list', function () {
    ConsoleSourceFolder::add(new Console('snes'));

    Livewire::test('consoles.index')
        ->assertSee('Manage console')
        ->assertSee(route('console-config.edit', ['console' => 'snes']), escape: false);
});

it('opens the settings form for the console the address names', function () {
    Livewire::withQueryParams(['console' => 'snes'])->test('settings.consoles')
        ->assertSet('editing', 'snes')
        // Flux modals are opened by an event rather than by state, so this is
        // what says the form is actually on screen and not merely selected.
        ->assertDispatched('modal-show')
        ->assertSee('Super Nintendo');
});

it('renders the settings page with a console named in the address', function () {
    // The Livewire test drives mount() directly; this is the ordinary page
    // load, where the modal's open event has to survive the initial render.
    $this->get(route('console-config.edit', ['console' => 'snes']))
        ->assertOk()
        ->assertSee('Super Nintendo');
});

it('leaves the settings page alone when the address names no console', function () {
    Livewire::test('settings.consoles')
        ->assertSet('editing', '')
        ->assertNotDispatched('modal-show');
});

it('refuses a console the address made up', function () {
    // Left blank rather than kept, or the address would go on naming a console
    // that is not open and cannot be.
    Livewire::withQueryParams(['console' => 'notaconsole'])->test('settings.consoles')
        ->assertSet('editing', '')
        ->assertNotDispatched('modal-show');
});

/*
 * The rules between groups in the action menus. Each menu runs setup, then
 * artwork, then ratings, then whatever is left, and a rule stands between them.
 */

it('rules off each kind of action in the row menu', function () {
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    $game = Game::factory()->forConsole('psx')->matched(19256)->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);
    GameFile::factory()->for($game)->create(['path' => 'psx/t.bin', 'filename' => 't.bin', 'role' => FileRole::Track]);

    Livewire::withQueryParams(['view' => 'table'])->test('games.index')
        ->assertSeeInOrder([
            'Identify game', 'data-flux-menu-separator',
            'Fetch artwork', 'data-flux-menu-separator',
            'Fetch rating', 'data-flux-menu-separator',
            'Open game',
        ], escape: false);
});

it('rules artwork off from ratings in the shelf menu', function () {
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);

    Livewire::test('games.index', ['console' => 'snes'])
        ->assertSeeInOrder([
            'Re-fetch all artwork', 'data-flux-menu-separator', 'Fetch missing ratings',
        ], escape: false);
});

it('rules artwork off from ratings in the console list menu', function () {
    ConsoleSourceFolder::add(new Console('snes'));

    Livewire::test('consoles.index')
        ->assertSeeInOrder([
            'Re-fetch all artwork', 'data-flux-menu-separator', 'Fetch missing ratings',
        ], escape: false);
});

it('draws one rule between scan and artwork when the group between is empty', function () {
    // The SNES knows one arrangement and has no loader to write for, so the
    // group under Scan is empty and its own rule would sit on top of Scan's.
    ConsoleSourceFolder::add(new Console('snes'));

    $html = Livewire::test('consoles.index')->html();
    $scan = strpos($html, 'Scan folder');
    $between = substr($html, $scan, strpos($html, 'Fetch missing artwork') - $scan);

    expect(substr_count($between, 'data-flux-menu-separator'))->toBe(1);
});

it('offers scan in the menu rather than on the card', function () {
    ConsoleSourceFolder::add(new Console('snes'));

    Queue::fake();

    Livewire::test('consoles.index')
        ->assertSeeInOrder(['data-flux-menu', 'Scan folder'], escape: false)
        ->call('scan', 'snes');

    Queue::assertPushed(ScanConsoleFolder::class, fn (ScanConsoleFolder $job): bool => $job->console === 'snes');
});

it('leads the card with the games and puts the files under them', function () {
    File::ensureDirectoryExists($this->root.'/snes');

    foreach (['A.sfc', 'B.sfc', 'C.sfc'] as $filename) {
        File::put($this->root.'/snes/'.$filename, 'x');
    }

    ConsoleSourceFolder::add(new Console('snes'));
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'One', 'slug' => 'one']);

    // Games identified, then the files that make them, then the maker and year
    // out of the console's config.
    Livewire::test('consoles.index')
        ->assertSee('Nintendo · 1990')
        ->assertSeeInOrder(['>1<', 'game', '3 files'], escape: false);
});

it('keeps the rule where the first group has something in it', function () {
    // A PS2 folder arranged the way OPL expects is the one case with a loader
    // to write for, so there is a group above the artwork actions and a rule
    // belongs between them.
    ConsoleSourceFolder::add(new Console('ps2'), null, 'opl');

    Livewire::test('consoles.index')
        ->assertSeeInOrder([
            'Write OPL', 'data-flux-menu-separator', 'Fetch missing artwork',
        ], escape: false);
});

it('does not list status as a column', function () {
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Identified One', 'slug' => 'one']);
    Game::factory()->forConsole('snes')->create(['title' => 'Unknown', 'slug' => 'unknown']);

    // The column is gone: the menu says what a game is by which identify
    // item it offers. Which games still need identifying is a filter again,
    // for finding them, not a column on every row.
    Livewire::withQueryParams(['view' => 'table'])->test('games.index')
        ->assertDontSee('Not yet identified')
        ->assertSee('Identified One')
        ->assertSee('Unknown');
});

it('softens the menu open rather than snapping it', function () {
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);

    // The game page's menu is hand-rolled, so it carries Alpine's transition
    // rather than the popover one the Flux menus get from the stylesheet.
    $this->get(route('games.show', $game->routeParameters()))
        ->assertOk()
        ->assertSee('x-transition:enter', escape: false)
        ->assertSee('origin-top-right', escape: false);
});

/*
 * Sorting by year and filtering by player count, and the rules that group the
 * filter row into what / where / how ordered / how drawn.
 */

it('sorts by year with the undated last', function () {
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Older', 'slug' => 'older', 'release_date' => '1994']);
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Newer', 'slug' => 'newer', 'release_date' => '2001-09-14']);
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Undated', 'slug' => 'undated', 'release_date' => null]);

    // Descending alone puts NULL at the top on MariaDB, which reads as the
    // newest games being the ones with no date at all.
    Livewire::test('games.index')
        ->set('sort', 'year')
        ->assertSeeInOrder(['Newer', 'Older', 'Undated']);
});

it('counts an empty date as no date when sorting by year', function () {
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Dated', 'slug' => 'dated', 'release_date' => '1994']);

    // A match that came back without one stores '' rather than null, and both
    // mean the same thing here.
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Blank', 'slug' => 'blank', 'release_date' => '']);

    Livewire::test('games.index')
        ->set('sort', 'year')
        ->assertSeeInOrder(['Dated', 'Blank']);
});

it('filters by the player count as the provider spells it', function () {
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Solo', 'slug' => 'solo', 'players' => '1']);
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Versus', 'slug' => 'versus', 'players' => '1-2']);

    // Matched whole, not as a number: "1-2" is an ordinary answer and there is
    // no arithmetic that turns it into one figure without inventing it.
    Livewire::test('games.index')
        ->set('players', '1-2')
        ->assertSee('Versus')
        ->assertDontSee('Solo');
});

it('orders the player counts naturally', function () {
    foreach (['1', '10', '2', '1-4'] as $i => $count) {
        Game::factory()->forConsole('psx')->matched()->create([
            'title' => "Game {$i}", 'slug' => "game-{$i}", 'players' => $count,
        ]);
    }

    // A straight sort puts "10" between "1" and "2".
    Livewire::test('games.index')->assertSeeInOrder(['>1<', '>1-4<', '>2<', '>10<'], escape: false);
});

it('leaves out the player filter where the shelf has one answer', function () {
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Solo', 'slug' => 'solo', 'players' => '1']);

    // A select whose whole list is "1" filters nothing and takes the width of
    // one that does.
    Livewire::test('games.index', ['console' => 'snes'])->assertDontSee('Any players');
});

it('clears the player filter along with the rest', function () {
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Versus', 'slug' => 'versus', 'players' => '1-2']);
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Solo', 'slug' => 'solo', 'players' => '1']);

    Livewire::test('games.index')
        ->set('players', '1-2')
        ->set('sort', 'year')
        ->call('clear')
        ->assertSet('players', '')
        // Not the sort: it says how to read the library rather than which part
        // of it to show.
        ->assertSet('sort', 'year');
});

it('rules the filter row into its three jobs', function () {
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);

    // Filters | sort | view — search lives in the hero bar now. Two rules,
    // and they are hidden below lg where the row wraps and a rule would
    // point at nothing.
    $html = Livewire::test('games.index')->html();

    expect(substr_count($html, 'h-6 w-px shrink-0 bg-line'))->toBe(2);
});

it('searches from the hero bar, left of Actions, on a console shelf', function () {
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);

    $page = $this->get(route('consoles.games', ['console' => 'snes']))->assertOk();

    expect(substr_count($page->getContent(), 'wire:model.live.debounce.300ms="query"'))->toBe(1);

    $page->assertSeeInOrder(['Search titles', 'Actions', 'Any genre']);
});

it('searches from beside the heading on the whole library', function () {
    $page = $this->get(route('games.index'))->assertOk();

    expect(substr_count($page->getContent(), 'wire:model.live.debounce.300ms="query"'))->toBe(1);

    $page->assertSeeInOrder(['Games', 'Search titles', 'All consoles']);
});

it('names the layout among the shelf figures on every console', function () {
    ConsoleSourceFolder::add(new Console('ps2'), null, 'opl');
    Game::factory()->forConsole('ps2')->matched()->create(['title' => 'Okami', 'slug' => 'okami']);

    $this->get(route('consoles.games', ['console' => 'ps2']))
        ->assertOk()
        ->assertSeeInOrder(['Layout', 'Open PS2 Loader', 'Files', 'On disk']);

    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);

    // A console with one arrangement still says which it is.
    $this->get(route('consoles.games', ['console' => 'snes']))
        ->assertOk()
        ->assertSeeInOrder(['Layout', 'However it already is', 'Files', 'On disk']);
});

it('focuses the search on Ctrl+K and marks the view in force', function () {
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);

    $this->get(route('consoles.games', ['console' => 'snes']))
        ->assertOk()
        ->assertSee('x-on:keydown.ctrl.k.window.prevent', false)
        ->assertSee('x-on:keydown.meta.k.window.prevent', false);

    $html = Livewire::test('games.index')->call('setView', 'table')->html();

    expect($html)->toMatch('~aria-pressed="true"\s+aria-label="Show a list"~')
        ->and($html)->toMatch('~aria-pressed="false"\s+aria-label="Show covers"~');
});

it('reads the library\'s consoles once for the whole consoles page', function () {
    // It used to ask about each console on its own from half a dozen helpers,
    // and about all 135 for the add dialog: two hundred queries to read a
    // handful of rows.
    foreach (['snes', 'nes', 'megadrive', 'psx'] as $key) {
        ConsoleSourceFolder::add(new Console($key));
    }

    DB::enableQueryLog();

    Livewire::test('consoles.index')->assertSee('Super Nintendo');

    $reads = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'console_source_folders'))
        ->count();

    expect($reads)->toBe(1);
});

it('asks the same number of queries for a shelf of five games as for twenty', function () {
    // One query for the page's games, one for all their artwork: nothing is
    // fetched per card, so the count does not grow with the shelf.
    $queriesFor = function (int $games): int {
        Game::query()->delete();

        foreach (range(1, $games) as $i) {
            $game = Game::factory()->forConsole('snes')->matched()->create(['title' => "Game {$i}", 'slug' => "game-{$i}"]);
            Media::factory()->for($game)->ofType('box-2D', 'eu')->create();
        }

        // Each render as a fresh request would see it: nothing read by the
        // previous one, and the backdrop pool cold for both.
        AppSetting::flush();
        Cache::forget('shelf.backdrops.snes');
        app()->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test('games.index', ['console' => 'snes']);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // And the settings table and the filter lists once each, whatever the
        // page asks of them.
        expect(collect($log)->filter(fn (array $q): bool => str_contains($q['query'], 'app_settings'))->count())->toBeLessThanOrEqual(1)
            ->and(collect($log)->filter(fn (array $q): bool => str_contains($q['query'], 'distinct `genre`'))->count())->toBe(1);

        return count($log);
    };

    expect($queriesFor(20))->toBe($queriesFor(5));
});

it('says in words what every media type it offers is', function () {
    $this->get(route('media.edit'))
        ->assertOk()
        ->assertSee('box-2D-back')
        ->assertSee('Box back')
        ->assertSee('Cartridge or disc');

    // A type added to the catalogue without a description would show its bare
    // name again — the thing this exists to stop.
    foreach (MediaTypes::offered() as $type) {
        expect(MediaTypes::describe($type))->not->toBeNull("{$type} has no description");
    }
});
