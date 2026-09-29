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
use Livewire\Features\SupportTesting\Testable;
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

/** The titles the list would draw, in the order it would draw them. */
function listedTitles(Testable $component): array
{
    return $component->instance()->games->pluck('title')->all();
}

/** One console's card on the consoles page, as the page computes it. */
function consoleCard(string $key): array
{
    return Livewire::test('consoles.index')->instance()->added
        ->firstWhere(fn (array $row): bool => $row['console']->key === $key);
}

/*
 * One render of each page, with enough on it to reach the branches the
 * template draws. What the page says is not the point; that it draws at all is.
 */

it('renders the whole library, as covers and as a list', function () {
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    $rated = Game::factory()->forConsole('snes')->matched()->rated(84)->create(['title' => 'Super Mario World', 'slug' => 'smw']);
    Media::factory()->for($rated)->ofType('box-2D', 'eu')->create();

    $placeholder = Game::factory()->forConsole('psx')->create(['title' => 'Unknown', 'slug' => 'unknown']);
    GameFile::factory()->for($placeholder)->create(['path' => 'psx/u.bin', 'filename' => 'u.bin', 'role' => FileRole::Track]);

    $this->get(route('games.index'))->assertOk();
    $this->get(route('games.index', ['view' => 'table']))->assertOk();
});

it('renders one console\'s shelf', function () {
    // A PS2 arranged for OPL is the one shelf with a loader export in its menu.
    ConsoleSourceFolder::add(new Console('ps2'), null, 'opl');

    $game = Game::factory()->forConsole('ps2')->matched()->rated(90)->create(['title' => 'Okami', 'slug' => 'okami']);
    Media::factory()->for($game)->ofType('box-2D', 'eu')->create();
    Media::factory()->for($game)->ofType('fanart', 'us')->create(['size_bytes' => 400_000]);

    $this->get(route('consoles.games', ['console' => 'ps2']))->assertOk();
});

it('renders a game page, on each of its tabs', function () {
    $game = Game::factory()->forConsole('psx')->matched()->rated(84)->create(['title' => 'Final Fantasy IX', 'slug' => 'ff9']);
    GameFile::factory()->for($game)->create(['path' => 'psx/d1.bin', 'filename' => 'd1.bin', 'role' => FileRole::Track, 'disc_number' => 1]);
    GameFile::factory()->for($game)->missing()->create(['path' => 'psx/d2.bin', 'filename' => 'd2.bin', 'role' => FileRole::Track, 'disc_number' => 2]);
    Media::factory()->for($game)->ofType('box-2D', 'eu')->create();
    Media::factory()->for($game)->ofType('wheel', 'eu')->create();

    $url = route('games.show', $game->routeParameters());

    $this->get($url)->assertOk();
    $this->get($url.'?tab=artwork')->assertOk();
});

it('renders the consoles page', function () {
    ConsoleSourceFolder::add(new Console('snes'));
    ConsoleSourceFolder::add(new Console('ps2'), null, 'opl');
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);

    $this->get(route('consoles.index'))->assertOk();
});

it('lists nothing until a console is added, whatever is on disk', function () {
    // The reason this exists: a collection copied wholesale leaves a folder for
    // every console it has ever heard of. Listing all of them buries the few
    // that hold games.
    foreach (['snes', 'psx', 'n64', 'gb', 'gba', 'megadrive'] as $folder) {
        File::ensureDirectoryExists($this->root.'/'.$folder);
    }

    // Six folders on disk, nothing in the library. That gap is the whole point.
    expect(Livewire::test('consoles.index')->instance()->added)->toHaveCount(0);

    ConsoleSourceFolder::add(new Console('snes'));

    expect(Livewire::test('consoles.index')->instance()->added->map(fn (array $row): string => $row['console']->key)->all())
        ->toBe(['snes']);
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
    $offered = fn (): array => $component->instance()->choices->pluck('key')->all();

    // Brand as well as name: "sega" has to find the Dreamcast, which is not
    // called that.
    $component->set('search', 'sega');
    expect($offered())->toContain('dreamcast')->not->toContain('snes');

    $component->set('search', 'super nintendo');
    expect($offered())->toContain('snes')->not->toContain('dreamcast');
});

it('does not offer a console that is already in the library', function () {
    ConsoleSourceFolder::add(new Console('snes'));

    // Its own card is elsewhere on the page, so this asks the list on offer
    // rather than the DOM.
    expect(Livewire::test('consoles.index')->call('openAdd')->instance()->choices->pluck('key')->all())
        ->toContain('nes')
        ->not->toContain('snes');
});

it('fetches artwork for a whole console, skipping the games that already have all it offers', function () {
    Queue::fake();
    ConsoleSourceFolder::add(new Console('snes'));

    $bare = Game::factory()->forConsole('snes')->create(['screenscraper_id' => 101]);
    $dressed = Game::factory()->forConsole('snes')->create(['screenscraper_id' => 102]);
    Media::factory()->for($dressed)->ofType('box-2D', 'eu')->create();
    // Everything its kept list offers is already here.
    $dressed->rememberMediaList([['type' => 'box-2D', 'region' => 'eu', 'url' => 'https://api.screenscraper.fr/x']]);

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

    Livewire::test('consoles.index')->call('remove', 'snes');

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

    $component = Livewire::test('games.index');

    expect(listedTitles($component))->toBe(['Super Mario World', 'Tekken 3']);

    $component->set('query', 'Tekken');
    expect(listedTitles($component))->toBe(['Tekken 3']);

    $component->set('query', '')->set('consoleFilter', 'snes');
    expect(listedTitles($component))->toBe(['Super Mario World']);

    $component->set('consoleFilter', '');
    expect(listedTitles($component))->toBe(['Super Mario World', 'Tekken 3']);
});

it('marks the missing files among a game\'s own', function () {
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Final Fantasy IX', 'slug' => 'ff9']);
    GameFile::factory()->for($game)->create(['path' => 'psx/d1.bin', 'filename' => 'd1.bin', 'role' => FileRole::Track, 'disc_number' => 1]);
    GameFile::factory()->for($game)->missing()->create(['path' => 'psx/d2.bin', 'filename' => 'd2.bin', 'role' => FileRole::Track, 'disc_number' => 2]);

    $rows = Livewire::test('games.show', ['game' => $game])->instance()->fileRows;

    expect(array_column($rows, 'missing', 'filename'))->toEqual(['d1.bin' => false, 'd2.bin' => true]);
});

it('puts the panels behind tabs, offering only the ones the game has', function () {
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Final Fantasy IX', 'slug' => 'ff9']);
    GameFile::factory()->for($game)->create(['path' => 'psx/d1.bin', 'filename' => 'd1.bin']);

    $tabs = fn (Testable $component): array => array_column($component->instance()->contentTabs, 'key');

    // No set and nothing downloaded: one tab, and its panel is the page.
    $component = Livewire::test('games.show', ['game' => $game])->assertSet('tab', '');

    expect($tabs($component))->toBe(['files'])
        ->and($component->instance()->activeTab)->toBe('files');

    Media::factory()->for($game)->ofType('box-2D', 'eu')->create();

    // Artwork arriving puts a second tab up, and the table gives way to it.
    $component = Livewire::test('games.show', ['game' => $game]);

    expect($tabs($component))->toBe(['files', 'artwork'])
        ->and($component->instance()->activeTab)->toBe('files');

    $component->call('selectTab', 'artwork');

    expect($component->instance()->activeTab)->toBe('artwork');

    // A tab this game does not have falls back to the first one rather than
    // leaving the page empty under the row.
    $component->set('tab', 'achievements');

    expect($component->instance()->activeTab)->toBe('files');
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

    $component->set('genre', 'Role Playing Game');
    expect(listedTitles($component))->toBe(['Vagrant Story']);

    // Matched as a whole entry: "Action" must not drag in every game whose
    // genre merely starts with it.
    $component->set('genre', 'Action');
    expect(listedTitles($component))->toBe(['Fighting Force']);
});

it('keeps every library page behind the login', function () {
    auth()->logout();

    foreach ([route('consoles.index'), route('games.index'), route('consoles.games', ['console' => 'snes']), route('media.edit')] as $url) {
        $this->get($url)->assertRedirect();
    }
});

it('renders the dashboard on an empty library and on a full one', function () {
    // $hero comes from the newest game, which does not exist before a scan.
    $this->get(route('dashboard'))->assertOk();

    $game = Game::factory()->forConsole('snes')->unmatched()->create(['title' => 'Unknown Disc', 'slug' => 'unknown']);
    GameFile::factory()->for($game)->create(['path' => 'snes/unknown.sfc', 'filename' => 'unknown.sfc', 'size_bytes' => 3145728]);

    $this->get(route('dashboard'))->assertOk();
});

it('identifies one game from the list', function () {
    Queue::fake();
    $game = Game::factory()->forConsole('psx')->create(['title' => 'Unknown Disc', 'slug' => 'unknown']);
    GameFile::factory()->for($game)->create([
        'path' => 'psx/a.bin', 'filename' => 'a.bin', 'extension' => 'bin', 'role' => FileRole::Track,
    ]);

    Livewire::test('games.index')->call('identify', $game->id);

    Queue::assertPushed(MatchGame::class, fn ($job) => $job->gameId === $game->id);
});

it('will not identify a game already identified', function () {
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);
    GameFile::factory()->for($game)->create(['path' => 'psx/t.bin', 'filename' => 't.bin', 'role' => FileRole::Track]);

    expect($game->canBeIdentified())->toBeFalse()
        ->and($game->blockedFromLookup())->toBe('Already identified.');
});

it('will not wait on a game that cannot be identified', function () {
    // A playlist with no data track: nothing here the provider indexes.
    $game = Game::factory()->forConsole('psx')->create(['title' => 'Orphan', 'slug' => 'orphan']);
    GameFile::factory()->for($game)->role(FileRole::Playlist)->create(['path' => 'psx/o.m3u', 'filename' => 'o.m3u']);

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

    $component = Livewire::test('games.show', ['game' => $game])->call('identify');

    $component->assertNotSet('awaiting', null);

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

it('fetches artwork for an identified game from the list', function () {
    Queue::fake();
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);
    GameFile::factory()->for($game)->create(['path' => 'psx/t.bin', 'filename' => 't.bin', 'role' => FileRole::Track]);

    Livewire::test('games.index')->call('fetchMedia', $game->id);

    Queue::assertPushed(ScrapeGameMedia::class, fn ($job) => $job->gameId === $game->id);
});

it('offers artwork only once a game has been identified', function () {
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    $placeholder = Game::factory()->forConsole('psx')->create(['title' => 'Unknown', 'slug' => 'unknown']);
    GameFile::factory()->for($placeholder)->create(['path' => 'psx/u.bin', 'filename' => 'u.bin', 'role' => FileRole::Track]);

    expect($placeholder->canFetchMedia())->toBeFalse()
        ->and($placeholder->blockedFromMediaScrape())->toContain('Identify the game first');
});

it('says so when no media type is switched on rather than queueing nothing', function () {
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);

    // Written out, because an absent setting now means the shipped selection.
    // Switching everything off is a choice somebody made, and only an empty
    // stored list says so.
    AppSetting::put(AppSetting::MEDIA_TYPES, []);

    // The job would run, find nothing switched on and return having done
    // nothing at all.
    expect(MediaTypes::enabled())->toBe([])
        ->and($game->canFetchMedia())->toBeFalse();
});

it('waits for artwork to arrive, then stops', function () {
    Queue::fake();
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);

    $component = Livewire::test('games.show', ['game' => $game])->call('fetchMedia');

    // A string, so there is no count that reads as "not waiting": assertNotSet
    // compares loosely and 0 == null in PHP.
    $component->assertSet('fetchingFrom', '0:0');
    Queue::assertPushed(ScrapeGameMedia::class);

    $component->call('checkMedia')->assertSet('fetchingFrom', '0:0');

    Media::factory()->for($game)->create();

    $component->call('checkMedia')->assertSet('fetchingFrom', null, true);
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

    $component->call('checkMedia')->assertSet('fetchingFrom', null, true);
});

it('gives up waiting when the provider holds no artwork', function () {
    Queue::fake();
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Obscure', 'slug' => 'obscure']);

    $component = Livewire::test('games.show', ['game' => $game])->call('fetchMedia');

    $component->call('checkMedia')->assertNotSet('fetchingFrom', null);

    $this->travel(3)->minutes();

    $component->call('checkMedia')->assertSet('fetchingFrom', null, true);
});

it('saves a preferred media region', function () {
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    Livewire::test('settings.media')
        ->assertSet('region', '')
        ->set('region', 'se')
        ->call('save');

    expect(MediaRegions::preferred())->toBe('se')
        // The preference leads, and the neutral entries stay behind it.
        ->and(MediaRegions::chain())->toBe(['se', 'ss', 'wor', 'eu', 'us', 'jp']);
});

it('lists one console on its own shelf, and 404s on a key config does not carry', function () {
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Vagrant Story', 'slug' => 'vagrant']);

    expect(listedTitles(Livewire::test('games.index', ['console' => 'snes'])))->toBe(['Super Mario World']);

    // Livewire fills any public property named after a route parameter, so a
    // filter called $console would come back as /consoles/snes?console=snes.
    Livewire::test('games.index', ['console' => 'snes'])->assertSet('consoleFilter', '');

    $this->get('/consoles/notaconsole')->assertNotFound();
});

it('addresses a game under its console, by slug', function () {
    $game = Game::factory()->forConsole('snes')->matched()->create(['title' => 'Aladdin', 'slug' => 'aladdin']);

    expect(route('games.show', $game->routeParameters()))->toEndWith('/consoles/snes/aladdin');

    $this->get('/consoles/snes/aladdin')->assertOk();
});

it('tells apart two consoles that each hold a game of the same name', function () {
    // Slugs are unique per console and nowhere else, which is the whole reason
    // the console is in the address.
    $snes = Game::factory()->forConsole('snes')->matched()->create(['title' => 'Aladdin (SNES)', 'slug' => 'aladdin']);
    $megadrive = Game::factory()->forConsole('megadrive')->matched()->create(['title' => 'Aladdin (Mega Drive)', 'slug' => 'aladdin']);

    $this->get('/consoles/megadrive/aladdin')->assertOk();
    expect(app('router')->current()->parameter('game')->is($megadrive))->toBeTrue();

    $this->get('/consoles/snes/aladdin')->assertOk();
    expect(app('router')->current()->parameter('game')->is($snes))->toBeTrue();
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

it('counts only the identified games on each console', function () {
    ConsoleSourceFolder::add(new Console('snes'));
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'One', 'slug' => 'one']);
    // A file the provider could not name is a file, not yet a game.
    Game::factory()->forConsole('snes')->create(['title' => 'Unknown', 'slug' => 'unknown']);

    expect(consoleCard('snes')['identified'])->toBe(1);
});

it('remembers whether the library is drawn as covers or as a list', function () {
    $mode = fn (Testable $component): string => $component->instance()->viewMode;

    // Cards by default.
    $component = Livewire::test('games.index');

    expect($mode($component))->toBe('cards');

    $component->call('setView', 'table');

    expect($mode($component))->toBe('table')
        ->and(AppSetting::get(AppSetting::UI_GAMES_VIEW))->toBe('table');

    // The memo outlives the component, and the point is that the next visit
    // reads the row back.
    AppSetting::flush();

    expect($mode(Livewire::test('games.index')))->toBe('table')
        ->and($mode(Livewire::test('games.index', ['console' => 'snes'])))->toBe('table');

    // Anything else leaves it alone rather than drawing nothing at all.
    expect($mode(Livewire::test('games.index')->call('setView', 'mosaic')))->toBe('table');

    // A link in the other mode still overrides what was remembered. Last,
    // because withQueryParams sticks to every Livewire::test after it.
    expect($mode(Livewire::withQueryParams(['view' => 'cards'])->test('games.index')))->toBe('cards');
});

it('says how many files an export has written, not how many jobs it queued', function () {
    ExportProgress::advance('ps2', 'cfg', 12, 19);

    expect(Livewire::test('system-activity')->viewData('exports'))
        ->toMatchArray(['busy' => true, 'done' => 12, 'total' => 19]);

    ExportProgress::finish('ps2', 'cfg');

    // Only the count goes; the queue's own figure is what is left.
    expect(Livewire::test('system-activity')->viewData('exports'))
        ->toMatchArray(['busy' => false, 'total' => 0]);
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

    expect(consoleCard('ps2')['files'])->toBe(3);
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

    expect(consoleCard('ps2')['files'])->toBe(1);
});

it('does not count a bios dump as a game', function () {
    File::ensureDirectoryExists($this->root.'/ps2');

    File::put($this->root.'/ps2/Game.iso', 'x');
    // PS2 lists .bin under both file_extensions and bios_extensions. The
    // scanner resolves that in favour of the game, and so does the card.
    File::put($this->root.'/ps2/scph39001.bin', 'x');

    ConsoleSourceFolder::add(new Console('ps2'));

    expect(consoleCard('ps2')['files'])->toBe(2);
});

it('never reads the disk to draw the page', function () {
    File::ensureDirectoryExists($this->root.'/ps2');
    File::put($this->root.'/ps2/One.iso', 'x');

    ConsoleSourceFolder::add(new Console('ps2'));

    expect(consoleCard('ps2')['files'])->toBe(1);

    File::put($this->root.'/ps2/Two.iso', 'x');

    // Still the stored count: the page reads what MeasureLibrary left and
    // never walks a folder itself, however often it renders.
    expect(consoleCard('ps2')['files'])->toBe(1);

    FolderCounts::recount(new Console('ps2'));

    expect(consoleCard('ps2')['files'])->toBe(2);
});

it('counts the folder again once a scan has walked it', function () {
    File::ensureDirectoryExists($this->root.'/ps2');
    File::put($this->root.'/ps2/One.iso', 'x');

    ConsoleSourceFolder::add(new Console('ps2'));

    expect(consoleCard('ps2')['files'])->toBe(1);

    File::put($this->root.'/ps2/Two.iso', 'x');

    // Faked so the scan's follow-up work — a provider lookup per new game —
    // stays out of a test that is only about the count. The recount itself
    // runs, since it is the thing under test.
    Queue::fake()->except(MeasureLibrary::class);

    (new ScanConsoleFolder('ps2'))->handle(app(LibraryScanner::class));

    expect(consoleCard('ps2')['files'])->toBe(2);
});

it('says nothing rather than throwing when the drive is not mounted', function () {
    ConsoleSourceFolder::add(new Console('ps2'));

    File::deleteDirectory($this->root.'/ps2');

    // A card that renders is worth more than a page that does not. Finding out
    // the mount has gone is the scan's job, not the list's.
    expect(consoleCard('ps2')['files'])->toBe(0);
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
    expect(listedTitles(Livewire::test('games.index')->set('sort', 'rating')))
        ->toBe(['Excellent', 'Middling', 'Unrated']);
});

it('sorts by title by default, whatever the ratings say', function () {
    Game::factory()->forConsole('snes')->matched()->rated(95)->create(['title' => 'Zeta', 'slug' => 'zeta']);
    Game::factory()->forConsole('snes')->matched()->rated(20)->create(['title' => 'Alpha', 'slug' => 'alpha']);

    expect(listedTitles(Livewire::test('games.index')))->toBe(['Alpha', 'Zeta']);
});

it('filters out everything below the rating asked for', function () {
    Game::factory()->forConsole('snes')->matched()->rated(95)->create(['title' => 'Excellent', 'slug' => 'excellent']);
    Game::factory()->forConsole('snes')->matched()->rated(64)->create(['title' => 'Middling', 'slug' => 'mid']);
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Unrated', 'slug' => 'unrated']);

    // A game with no rating is not a game rated below eighty, but it is not
    // one the filter was asked for either.
    expect(listedTitles(Livewire::test('games.index')->set('minRating', '80')))->toBe(['Excellent']);
});

it('lists only the games still waiting to be identified, or only the identified', function () {
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Known Quantity', 'slug' => 'known']);
    Game::factory()->forConsole('snes')->create(['title' => 'Fresh From Disk', 'slug' => 'fresh', 'status' => GameStatus::Placeholder]);
    Game::factory()->forConsole('snes')->unmatched()->create(['title' => 'Nowhere To Be Found', 'slug' => 'nowhere']);

    $component = Livewire::test('games.index', ['console' => 'snes']);

    // Needed takes in both: never looked up, and looked up with no match.
    $component->set('identified', 'needed');
    expect(listedTitles($component))->toBe(['Fresh From Disk', 'Nowhere To Be Found']);

    $component->set('identified', 'identified');
    expect(listedTitles($component))->toBe(['Known Quantity']);

    $component->call('clear')->assertSet('identified', '');
});

it('clears every filter but keeps the sort', function () {
    Game::factory()->forConsole('psx')->matched()->rated(95)->create(['players' => '1-2']);
    Game::factory()->forConsole('psx')->matched()->create(['players' => '1']);

    // Clear is about which games are shown. How they are ordered is a way of
    // reading the library, and somebody who chose it meant it to stick.
    Livewire::test('games.index')
        ->set('sort', 'rating')
        ->set('minRating', '80')
        ->set('players', '1-2')
        ->set('query', 'nothing')
        ->call('clear')
        ->assertSet('minRating', '')
        ->assertSet('players', '')
        ->assertSet('query', '')
        ->assertSet('sort', 'rating');
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

/*
 * Asking for a rating by hand, from a console's menu or a game's.
 *
 * The backfill command reaches a whole library once; these are the two places
 * somebody asks about a shelf or a single game after the fact, which is the
 * only way to pick up votes cast since the match.
 */

it('fetches ratings for a whole console, forced only when starting over', function () {
    Queue::fake();
    ConsoleSourceFolder::add(new Console('snes'));

    // Which games are chosen is RateGame::queueForConsole's business, tested
    // in JobsTest; this is that the page reaches it.
    $game = Game::factory()->forConsole('snes')->matched(101)->create();

    Livewire::test('consoles.index')->call('fetchRatings', 'snes');

    Queue::assertPushed(RateGame::class, fn ($job) => $job->gameId === $game->id && $job->force === false);

    Queue::fake();

    // Forced, or the job's own guard would drop a game that already has a
    // rating — which is the very game somebody asking again means.
    Livewire::test('consoles.index')->call('fetchRatings', 'snes', true);

    Queue::assertPushed(RateGame::class, fn ($job) => $job->gameId === $game->id && $job->force === true);
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

it('fetches a rating again from the game page, forced', function () {
    Queue::fake();

    $game = Game::factory()->forConsole('psx')->matched(19256)->rated(70)->create([
        'title' => 'Tekken 3', 'slug' => 'tekken-3',
    ]);

    Livewire::test('games.show', ['game' => $game])->call('fetchRating');

    Queue::assertPushed(RateGame::class, fn ($job) => $job->gameId === $game->id && $job->force === true);
});

it('refuses a rating for a game nobody has identified', function () {
    Queue::fake();

    $game = Game::factory()->forConsole('psx')->create(['title' => 'Mystery', 'slug' => 'mystery']);

    Livewire::test('games.show', ['game' => $game])->call('fetchRating');

    Queue::assertNothingPushed();
});

it('waits for a rating to arrive, then stops', function () {
    Queue::fake();

    $game = Game::factory()->forConsole('psx')->matched(19256)->create([
        'title' => 'Tekken 3', 'slug' => 'tekken-3',
    ]);

    $component = Livewire::test('games.show', ['game' => $game])->call('fetchRating');

    // A string, so an unrated game does not read as "not waiting": null means
    // idle here, the same as it does for artwork.
    $component->assertSet('ratingFrom', '', true);

    $component->call('checkRating')->assertSet('ratingFrom', '', true);

    $game->update(['rating' => 90]);

    $component->call('checkRating')->assertSet('ratingFrom', null, true);
});

it('gives up waiting when the provider holds no rating', function () {
    Queue::fake();

    $game = Game::factory()->forConsole('psx')->matched(19256)->create([
        'title' => 'Obscure', 'slug' => 'obscure',
    ]);

    // Nothing is written for a game nobody has voted on, so there is no
    // arrival to notice — the same shape as artwork the provider does not hold.
    $component = Livewire::test('games.show', ['game' => $game])->call('fetchRating');

    $component->call('checkRating')->assertSet('ratingFrom', '', true);

    $this->travel(3)->minutes();

    $component->call('checkRating')->assertSet('ratingFrom', null, true);
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
 * The console shelf's hero, which borrows its key art from the games on the
 * shelf because nothing ships artwork per console.
 */

it('carries key art from the console\'s own games behind the shelf header', function () {
    $game = Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);
    $art = Media::factory()->for($game)->ofType('fanart', 'us')->create(['size_bytes' => 400_000]);

    Livewire::test('games.index', ['console' => 'snes'])->assertSet('heroArt', $art->path);
});

it('will not blow up a screenshot or a thumbnail behind the header', function () {
    $game = Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);

    // An in-game grab is filed as a backdrop beside real wallpaper, and a
    // piece of key art can still arrive thumbnail sized.
    Media::factory()->for($game)->ofType('ss', 'us')->create(['size_bytes' => 400_000]);
    Media::factory()->for($game)->ofType('fanart', 'us')->create(['size_bytes' => 4_000]);

    Livewire::test('games.index', ['console' => 'snes'])->assertSet('heroArt', null, true);
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

it('counts the console in the shelf header', function () {
    $matched = Game::factory()->forConsole('snes')->matched()->create(['title' => 'Identified One', 'slug' => 'one']);
    GameFile::factory()->for($matched)->create(['path' => 'snes/one.sfc', 'filename' => 'one.sfc', 'size_bytes' => 3_145_728]);

    $placeholder = Game::factory()->forConsole('snes')->create(['title' => 'Unknown', 'slug' => 'unknown']);
    GameFile::factory()->for($placeholder)->create(['path' => 'snes/u.sfc', 'filename' => 'u.sfc', 'size_bytes' => 1_048_576]);

    // Another console's games are not this shelf's business.
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Elsewhere', 'slug' => 'elsewhere']);

    // One game, because the placeholder is not one yet; its file still counts
    // towards the size. No folder on disk in this test, so no files either.
    expect(Livewire::test('games.index', ['console' => 'snes'])->instance()->consoleStats)
        ->toMatchArray(['games' => 1, 'files' => 0, 'size' => '4.0 MB']);
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
    expect(Livewire::test('games.index', ['console' => 'psx'])->instance()->consoleStats)
        ->toMatchArray(['games' => 1, 'size' => '4.0 MB']);
});

/*
 * The shelf hero's Actions menu, which runs the console's actions from the
 * console's own page rather than only from the list of all of them.
 */

it('scans this console from its own shelf', function () {
    Queue::fake();

    Livewire::test('games.index', ['console' => 'snes'])->call('scanConsole');

    Queue::assertPushed(ScanConsoleFolder::class, fn ($job) => $job->console === 'snes');
});

it('fetches artwork and ratings for this console from its own shelf', function () {
    Queue::fake();
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D']);

    $game = Game::factory()->forConsole('snes')->matched(101)->create();

    // Another console's games are not this shelf's business, however the menu
    // was reached.
    Game::factory()->forConsole('psx')->matched(103)->create();

    Livewire::test('games.index', ['console' => 'snes'])
        ->call('fetchConsoleMedia')
        ->call('fetchConsoleRatings', true);

    Queue::assertPushed(ScrapeGameMedia::class, 1);
    Queue::assertPushed(ScrapeGameMedia::class, fn ($job) => $job->gameId === $game->id);
    Queue::assertPushed(RateGame::class, 1);
    Queue::assertPushed(RateGame::class, fn ($job) => $job->gameId === $game->id && $job->force === true);
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
        ->call('writeConsoleExport', 'cfg')
        ->assertDispatched('toast-show');

    Queue::assertPushedOn('toolbox', WriteConsoleExports::class, function (WriteConsoleExports $job): bool {
        return $job->console === 'ps2' && $job->export === 'cfg';
    });
});

it('refuses an export the console\'s toolbox does not write', function () {
    Queue::fake();
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'Super Mario World', 'slug' => 'smw']);

    // The SNES has no loader files to write, so the item is not drawn — and the
    // handler refuses the name even when it is called directly, which is the
    // half that a hidden menu item does not cover.
    Livewire::test('games.index', ['console' => 'snes'])->call('writeConsoleExport', 'cfg');

    Queue::assertNothingPushed();
});

/*
 * What the console config says about the machine itself.
 */

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

it('opens the settings form for the console the address names', function () {
    Livewire::withQueryParams(['console' => 'snes'])->test('settings.consoles')
        ->assertSet('editing', 'snes')
        // Flux modals are opened by an event rather than by state, so this is
        // what says the form is actually on screen and not merely selected.
        ->assertDispatched('modal-show');
});

it('renders the settings page with a console named in the address', function () {
    // The Livewire test drives mount() directly; this is the ordinary page
    // load, where the modal's open event has to survive the initial render.
    $this->get(route('console-config.edit', ['console' => 'snes']))->assertOk();
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

it('scans a console from the console list', function () {
    ConsoleSourceFolder::add(new Console('snes'));

    Queue::fake();

    Livewire::test('consoles.index')->call('scan', 'snes');

    Queue::assertPushed(ScanConsoleFolder::class, fn (ScanConsoleFolder $job): bool => $job->console === 'snes');
});

/*
 * Sorting by year and filtering by player count.
 */

it('sorts by year with the undated last', function () {
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Older', 'slug' => 'older', 'release_date' => '1994']);
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Newer', 'slug' => 'newer', 'release_date' => '2001-09-14']);
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Undated', 'slug' => 'undated', 'release_date' => null]);

    // Descending alone puts NULL at the top on MariaDB, which reads as the
    // newest games being the ones with no date at all.
    expect(listedTitles(Livewire::test('games.index')->set('sort', 'year')))
        ->toBe(['Newer', 'Older', 'Undated']);
});

it('counts an empty date as no date when sorting by year', function () {
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Dated', 'slug' => 'dated', 'release_date' => '1994']);

    // A match that came back without one stores '' rather than null, and both
    // mean the same thing here.
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Blank', 'slug' => 'blank', 'release_date' => '']);

    expect(listedTitles(Livewire::test('games.index')->set('sort', 'year')))->toBe(['Dated', 'Blank']);
});

it('filters by the player count as the provider spells it', function () {
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Solo', 'slug' => 'solo', 'players' => '1']);
    Game::factory()->forConsole('psx')->matched()->create(['title' => 'Versus', 'slug' => 'versus', 'players' => '1-2']);

    // Matched whole, not as a number: "1-2" is an ordinary answer and there is
    // no arithmetic that turns it into one figure without inventing it.
    expect(listedTitles(Livewire::test('games.index')->set('players', '1-2')))->toBe(['Versus']);
});

it('orders the player counts naturally', function () {
    foreach (['1', '10', '2', '1-4'] as $i => $count) {
        Game::factory()->forConsole('psx')->matched()->create([
            'title' => "Game {$i}", 'slug' => "game-{$i}", 'players' => $count,
        ]);
    }

    // A straight sort puts "10" between "1" and "2".
    expect(Livewire::test('games.index')->instance()->playerCounts->all())->toBe(['1', '1-4', '2', '10']);
});

it('reads the library\'s consoles once for the whole consoles page', function () {
    // It used to ask about each console on its own from half a dozen helpers,
    // and about all 135 for the add dialog: two hundred queries to read a
    // handful of rows.
    foreach (['snes', 'nes', 'megadrive', 'psx'] as $key) {
        ConsoleSourceFolder::add(new Console($key));
    }

    DB::enableQueryLog();

    Livewire::test('consoles.index');

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

it('describes every media type it offers', function () {
    // A type added to the catalogue without a description would show its bare
    // name again — the thing this exists to stop.
    foreach (MediaTypes::offered() as $type) {
        expect(MediaTypes::describe($type))->not->toBeNull("{$type} has no description");
    }
});
