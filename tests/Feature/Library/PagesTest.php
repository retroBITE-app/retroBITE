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
use App\Services\LibraryScanner;
use App\Support\Console;
use App\Support\ExportProgress;
use App\Support\MediaRegions;
use App\Support\MediaTypes;
use App\Support\Scanning\FolderCounts;
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
    Queue::fake();
    ConsoleSourceFolder::add(new Console('snes'));
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

    $this->get(route('games.show', $game))
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
    $this->get(route('games.show', $game))
        ->assertOk()
        ->assertDontSeeHtml('rounded-md border border-line-strong bg-surface px-2 py-0.5 font-mono text-xs text-fg-muted');
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

    // The strip itself lives behind the Artwork tab, which the URL names, so
    // a link into it opens on the pictures rather than on the file table.
    $strip = $this->get(route('games.show', $game).'?tab=artwork')->assertOk();

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
    $this->get(route('games.show', $game).'?tab=achievements')->assertOk()->assertSee($table);
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
    // filter called $console would come back as /snes/games?console=snes.
    Livewire::test('games.index', ['console' => 'snes'])->assertSet('consoleFilter', '');

    $this->get('/notaconsole/games')->assertNotFound();
});

it('remembers whether the library is drawn as covers or as a list', function () {
    $game = Game::factory()->forConsole('snes')->unmatched()->create(['title' => 'Unknown Disc', 'slug' => 'unknown']);
    GameFile::factory()->for($game)->create(['path' => 'snes/unknown.sfc', 'filename' => 'unknown.sfc', 'size_bytes' => 3145728]);

    // Cards by default, and a card carries the word the table puts in a column
    // header instead.
    Livewire::test('games.index')
        ->assertSee('Unidentified')
        ->assertDontSee('Genre')
        ->call('setView', 'table')
        ->assertSee('Genre');

    expect(AppSetting::get(AppSetting::UI_GAMES_VIEW))->toBe('table');

    // The memo outlives the component, and the point is that the next visit
    // reads the row back.
    AppSetting::flush();

    Livewire::test('games.index')->assertSee('Genre');
    Livewire::test('games.index', ['console' => 'snes'])->assertSee('Genre');

    // Anything else leaves it alone rather than drawing nothing at all.
    Livewire::test('games.index')->call('setView', 'mosaic')->assertSee('Genre');

    // A link in the other mode still overrides what was remembered. Last,
    // because withQueryParams sticks to every Livewire::test after it.
    Livewire::withQueryParams(['view' => 'cards'])->test('games.index')->assertDontSee('Genre');
});

it('sizes an empty slot from the console it belongs to', function () {
    // Real art supplies its own width; a placeholder has none, so the
    // console's ratio stands in and the slot holds a cover's worth of space.
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Vagrant Story', 'slug' => 'vagrant']);

    // 2/3 and 5/7 of the same 280px.
    Livewire::test('games.index', ['console' => 'snes'])->assertSee('width: 187px', escape: false);
    Livewire::test('games.index', ['console' => 'psx'])->assertSee('width: 200px', escape: false);
});

it('says how many files an export has written, not how many jobs it queued', function () {
    ConsoleSourceFolder::add(new Console('ps2'), null, 'opl');

    ExportProgress::advance('ps2', 'cfg', 12, 19);

    Livewire::test('consoles.index')
        ->assertSee('Writing files')
        ->assertSeeInOrder(['12', '/19']);

    ExportProgress::finish('ps2', 'cfg');

    Livewire::test('consoles.index')->assertDontSee('Writing files');
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

    Livewire::test('consoles.index')->assertSee('3 games');
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

    Livewire::test('consoles.index')->assertSee('1 games');
});

it('does not count a bios dump as a game', function () {
    File::ensureDirectoryExists($this->root.'/ps2');

    File::put($this->root.'/ps2/Game.iso', 'x');
    // PS2 lists .bin under both file_extensions and bios_extensions. The
    // scanner resolves that in favour of the game, and so does the card.
    File::put($this->root.'/ps2/scph39001.bin', 'x');

    ConsoleSourceFolder::add(new Console('ps2'));

    Livewire::test('consoles.index')->assertSee('2 games');
});

it('does not walk the drive again on every poll', function () {
    File::ensureDirectoryExists($this->root.'/ps2');
    File::put($this->root.'/ps2/One.iso', 'x');

    ConsoleSourceFolder::add(new Console('ps2'));

    Livewire::test('consoles.index')->assertSee('1 games');

    File::put($this->root.'/ps2/Two.iso', 'x');

    // Still the cached answer: the page polls itself every two seconds, and
    // re-walking a five-thousand-file drive each time is the whole reason the
    // count is cached at all.
    Livewire::test('consoles.index')->assertSee('1 games');

    FolderCounts::forget(new Console('ps2'));

    Livewire::test('consoles.index')->assertSee('2 games');
});

it('forgets the count once a scan has walked the folder', function () {
    File::ensureDirectoryExists($this->root.'/ps2');
    File::put($this->root.'/ps2/One.iso', 'x');

    ConsoleSourceFolder::add(new Console('ps2'));

    Livewire::test('consoles.index')->assertSee('1 games');

    File::put($this->root.'/ps2/Two.iso', 'x');

    // Faked so the scan's follow-up work — a provider lookup per new game —
    // stays out of a test that is only about the cached count.
    Queue::fake();

    (new ScanConsoleFolder('ps2'))->handle(app(LibraryScanner::class));

    Livewire::test('consoles.index')->assertSee('2 games');
});

it('says nothing rather than throwing when the drive is not mounted', function () {
    ConsoleSourceFolder::add(new Console('ps2'));

    File::deleteDirectory($this->root.'/ps2');

    // A card that renders is worth more than a page that does not. Finding out
    // the mount has gone is the scan's job, not the list's.
    Livewire::test('consoles.index')->assertSee('0 games');

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
