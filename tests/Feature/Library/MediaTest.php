<?php

use App\Enums\FileRole;
use App\Enums\MediaKind;
use App\Jobs\MatchGame;
use App\Jobs\RateGame;
use App\Jobs\ScrapeGameMedia;
use App\Models\AppSetting;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Services\GameMatcher;
use App\Services\MediaLibrary;
use App\Services\ScreenScraperService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

const PNG = "\x89PNG\r\n\x1a\n";

beforeEach(function () {
    Storage::fake('media');

    // video is absent, which is what "switched off" means now.
    AppSetting::put(AppSetting::MEDIA_TYPES, ['box-2D', 'ss']);

    $this->game = Game::factory()->forConsole('psx')->matched(19256)->create([
        'title' => 'Final Fantasy IX', 'slug' => 'final-fantasy-ix',
    ]);
});

function entry(string $type, string $body = 'ignored', ?string $md5 = null, ?string $region = 'us'): array
{
    return [
        'type' => $type,
        // sanitizeMedias() keeps only artwork belonging to the game itself;
        // the same list carries the publisher's and the genre's pictograms.
        'parent' => 'jeu',
        'region' => $region,
        'format' => 'png',
        'url' => "https://api.screenscraper.fr/api2/mediaJeu.php?jeuid=19256&media={$type}",
        'md5' => $md5 ?? md5($body),
    ];
}

function runScrape(Game $game, array $medias): void
{
    (new ScrapeGameMedia($game->id, $medias))->handle(
        app(ScreenScraperService::class),
        app(MediaLibrary::class),
    );
}

it('downloads only the types that are switched on', function () {
    $cover = PNG.'cover';
    $video = PNG.'video';
    Http::fake(['*' => Http::response($cover, 200)]);

    runScrape($this->game, [entry('box-2D', $cover), entry('video', $video)]);

    expect(Media::count())->toBe(1)
        ->and(Media::sole()->screenscraper_type)->toBe('box-2D');

    Http::assertSentCount(1);
});

it('stores the file under console, game and type', function () {
    $cover = PNG.'cover';
    Http::fake(['*' => Http::response($cover, 200)]);

    runScrape($this->game, [entry('box-2D', $cover)]);

    $media = Media::sole();
    $md5 = md5($cover);

    // The provider id joins the slug because titles are not unique — remakes
    // share one — while the id is unique per game.
    expect($media->path)->toBe("psx/final-fantasy-ix-19256/box-2d/{$md5}.png")
        ->and($media->md5)->toBe($md5)
        ->and($media->size_bytes)->toBe(strlen($cover))
        ->and($media->region)->toBe('us');

    Storage::disk('media')->assertExists($media->path);
    expect(Storage::disk('media')->get($media->path))->toBe($cover);
});

it('spends no request on an image it already holds', function () {
    $cover = PNG.'cover';
    Http::fake(['*' => Http::response($cover, 200)]);

    runScrape($this->game, [entry('box-2D', $cover)]);
    Http::assertSentCount(1);

    // The metadata already carried the checksum, so a second scrape can tell
    // it holds this file without asking at all.
    runScrape($this->game->refresh(), [entry('box-2D', $cover)]);

    expect(Media::count())->toBe(1);
    Http::assertSentCount(1);
});

it('writes nothing when the provider says our copy matches', function () {
    // Asked with a checksum the provider recognises, it answers MD5OK and no
    // bytes rather than sending the image again.
    Http::fake(['*' => Http::response('MD5OK', 200)]);

    runScrape($this->game, [entry('box-2D', 'x', md5: str_repeat('0', 32))]);

    expect(Media::count())->toBe(0);
    Storage::disk('media')->assertDirectoryEmpty('/');
});

it('keeps the box art when a screenshot fails', function () {
    $cover = PNG.'cover';

    Http::fake([
        '*media=box-2D*' => Http::response($cover, 200),
        '*media=ss*' => Http::response('', 500),
    ]);

    runScrape($this->game, [entry('ss'), entry('box-2D', $cover)]);

    // Losing a screenshot is not a reason to lose the cover too.
    expect(Media::count())->toBe(1)
        ->and(Media::sole()->screenscraper_type)->toBe('box-2D');
});

it('stops the whole job when the quota runs out', function () {
    Http::fake(['*' => Http::response(
        mb_convert_encoding("Votre quota de scrape est dépassé pour aujourd'hui !", 'ISO-8859-1', 'UTF-8'), 430
    )]);

    $job = Mockery::mock(ScrapeGameMedia::class.'[release]', [$this->game->id, [entry('box-2D'), entry('ss')]])
        ->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('release')->once();

    $job->handle(app(ScreenScraperService::class), app(MediaLibrary::class));

    // The next type would meet the same wall, so nothing else is attempted.
    expect(Media::count())->toBe(0);
    Http::assertSentCount(1);
});

it('puts the credentials back on a stored url that has none', function () {
    config()->set('screenscraper.user', 'tomas');
    config()->set('screenscraper.password', 'topsecret');
    Http::fake(['*' => Http::response(PNG.'x', 200)]);

    runScrape($this->game, [entry('box-2D', PNG.'x')]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'ssid=tomas')
        && str_contains($request->url(), 'sspassword=topsecret'));

    // What is persisted stays clean.
    expect(Media::sole()->source_url)->not->toContain('topsecret');
});

it('detects the file type from the bytes when the provider omits it', function () {
    Http::fake(['*' => Http::response("\xFF\xD8\xFF".'jpeg', 200)]);

    $entry = entry('box-2D', "\xFF\xD8\xFF".'jpeg');
    unset($entry['format']);

    runScrape($this->game, [$entry]);

    expect(Media::sole()->extension)->toBe('jpg');
});

it('deletes the row and the file together', function () {
    Http::fake(['*' => Http::response(PNG.'x', 200)]);
    runScrape($this->game, [entry('box-2D', PNG.'x')]);

    $media = Media::sole();
    $path = $media->path;

    app(MediaLibrary::class)->forget($media);

    // A row without its file leaks; a file without its row is unreachable.
    expect(Media::count())->toBe(0);
    Storage::disk('media')->assertMissing($path);
});

it('queues artwork after a match, and not when the setting is off', function () {
    Bus::fake();
    Http::fake(['*' => Http::response(['response' => [
        'jeu' => ['id' => '19256', 'noms' => [['region' => 'ss', 'text' => 'FF IX']], 'medias' => [entry('box-2D')]],
    ]], 200)]);

    $game = Game::factory()->forConsole('psx')->create();
    GameFile::factory()->for($game)->create(['path' => 'psx/a.bin', 'filename' => 'a.bin', 'extension' => 'bin', 'role' => FileRole::Track]);

    (new MatchGame($game->id))->handle(app(GameMatcher::class));

    Bus::assertDispatched(ScrapeGameMedia::class, function (ScrapeGameMedia $job) {
        // The list rides along, so fetching artwork costs no second lookup.
        return $job->medias !== null && $job->medias !== [];
    });

    AppSetting::put(AppSetting::AUTO_QUEUE_MEDIA_SCRAPE, false);

    $other = Game::factory()->forConsole('psx')->create();
    GameFile::factory()->for($other)->create(['path' => 'psx/b.bin', 'filename' => 'b.bin', 'extension' => 'bin', 'role' => FileRole::Track]);

    Bus::fake();
    (new MatchGame($other->id))->handle(app(GameMatcher::class));

    Bus::assertNotDispatched(ScrapeGameMedia::class);
});

it('never hands the provider back its own checksum', function () {
    // The bug this guards: passing entry.md5 as "the copy I already have" asks
    // a question the provider always answers MD5OK to, and MD5OK means no
    // bytes. Every media that came with a checksum was skipped and the library
    // stayed empty.
    $cover = PNG.'cover';
    Http::fake(['*' => Http::response($cover, 200)]);

    runScrape($this->game, [entry('box-2D', $cover)]);

    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'md5='));

    expect(Media::count())->toBe(1);
});

it('offers our own checksum when replacing artwork we hold', function () {
    $old = PNG.'old';
    $new = PNG.'new';

    // One sequence, not two fakes: Http::fake() merges stubs rather than
    // replacing them, so a second call with the same pattern never wins.
    Http::fake(['*' => Http::sequence()->push($old, 200)->push($new, 200)]);

    runScrape($this->game, [entry('box-2D', $old)]);

    // A second pass for the same type and region asks whether ours is current,
    // with the checksum of the bytes we actually stored.
    runScrape($this->game->refresh(), [entry('box-2D', $new)]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'md5='.md5($old)));

    // Replacing, not accumulating: a type and region is one slot, and the copy
    // that arrives last is the one the game holds. A game may hold a European
    // and a Japanese cover, never two European ones.
    expect(Media::count())->toBe(1)
        ->and(Media::first()->md5)->toBe(md5($new));

    // The old file goes with its row, or the disk keeps every cover the
    // provider has ever revised.
    expect(Storage::disk('media')->allFiles())->toHaveCount(1);
});

it('keeps each region side by side and lets the preference choose', function () {
    $europe = PNG.'europe';
    $japan = PNG.'japan';
    $list = [entry('box-2D', $europe, region: 'eu'), entry('box-2D', $japan, region: 'jp')];

    Http::fake(['*' => Http::sequence()->push($europe, 200)->push($japan, 200)]);

    AppSetting::put(AppSetting::MEDIA_REGION, 'eu');
    runScrape($this->game, $list);

    expect(Media::sole()->region)->toBe('eu');

    // The preference moves and the library is asked again. Both covers stay —
    // somebody can hold four regions of the same box on purpose — and the
    // preference decides which one the page leads with, not the file size.
    AppSetting::put(AppSetting::MEDIA_REGION, 'jp');
    runScrape($this->game->refresh(), $list);

    expect(Media::count())->toBe(2)
        ->and($this->game->refresh()->load('media')->artwork(MediaKind::Cover)->region)->toBe('jp');
});

it('fetches one named region and nothing else', function () {
    $europe = PNG.'europe';
    $japan = PNG.'japan';
    $shot = PNG.'shot';

    // entry() keys its URL on the type alone; here two entries share a type
    // and differ only by region, so the region has to reach the URL for the
    // fake to tell them apart — as it does in the provider's own list.
    $of = function (string $type, string $body, string $region) {
        $entry = entry($type, $body, region: $region);
        $entry['url'] .= '&region='.$region;

        return $entry;
    };

    $list = [
        $of('box-2D', $europe, 'eu'),
        $of('box-2D', $japan, 'jp'),
        // Only Europe has one, so a Japanese fetch leaves it alone rather
        // than spending a download on the copy already held.
        $of('ss', $shot, 'eu'),
    ];

    Http::fake([
        '*media=box-2D&region=eu*' => Http::response($europe, 200),
        '*media=box-2D&region=jp*' => Http::response($japan, 200),
        '*media=ss&region=eu*' => Http::response($shot, 200),
    ]);

    AppSetting::put(AppSetting::MEDIA_REGION, 'eu');
    runScrape($this->game, $list);

    expect(Media::pluck('region')->all())->toBe(['eu', 'eu']);

    (new ScrapeGameMedia($this->game->id, $list, 'jp'))->handle(
        app(ScreenScraperService::class),
        app(MediaLibrary::class),
    );

    expect(Media::where('region', 'jp')->pluck('screenscraper_type')->all())->toBe(['box-2D'])
        ->and(Media::count())->toBe(3);
});

it('records what each media type came back with', function () {
    $cover = PNG.'cover';

    Http::fake([
        '*media=box-2D*' => Http::response($cover, 200),
        '*media=ss*' => Http::response('NOMEDIA', 200),
    ]);

    runScrape($this->game, [entry('box-2D', $cover), entry('ss')]);

    $activity = Activity::where('log_name', 'screenscraper')->latest('id')->first();

    // The row somebody opens to ask why a cover never turned up.
    expect($activity->properties['endpoint'])->toBe('mediaJeu.php')
        ->and($activity->properties['outcomes']['box-2D'])->toBe('stored')
        ->and($activity->properties['outcomes']['ss'])->toBe('not held by the provider')
        ->and($activity->subject->is($this->game))->toBeTrue()
        // NOMEDIA is an ordinary answer: nothing stored for it, and no failure.
        ->and(Media::count())->toBe(1);
});

it('keeps one copy of a type, not every region the provider holds', function () {
    // The provider answers with a cover for each region it has. Storing them
    // all fills the disk with the same picture in four languages.
    $bodies = ['us' => PNG.'us', 'eu' => PNG.'eu', 'jp' => PNG.'jp'];
    Http::fake(['*' => Http::response($bodies['eu'], 200)]);

    AppSetting::put(AppSetting::MEDIA_REGION, 'eu');

    runScrape($this->game, [
        entry('box-2D', $bodies['us'], region: 'us'),
        entry('box-2D', $bodies['eu'], region: 'eu'),
        entry('box-2D', $bodies['jp'], region: 'jp'),
    ]);

    expect(Media::count())->toBe(1)
        ->and(Media::sole()->region)->toBe('eu');

    Http::assertSentCount(1);
});

it('falls back through the neutral regions when the preferred one is missing', function () {
    Http::fake(['*' => Http::response(PNG.'x', 200)]);

    AppSetting::put(AppSetting::MEDIA_REGION, 'se');

    runScrape($this->game, [
        entry('box-2D', 'a', region: 'jp'),
        entry('box-2D', 'b', region: 'wor'),
        entry('box-2D', 'c', region: 'us'),
    ]);

    // A World cover is a better stand-in for a missing Swedish one than a
    // Japanese cover is.
    expect(Media::sole()->region)->toBe('wor');
});

it('takes whatever exists when nothing in the chain matches', function () {
    Http::fake(['*' => Http::response(PNG.'x', 200)]);

    AppSetting::put(AppSetting::MEDIA_REGION, 'se');

    runScrape($this->game, [entry('box-2D', 'a', region: 'it')]);

    // Better an Italian cover than none.
    expect(Media::sole()->region)->toBe('it');
});

it('passes region-less media straight through', function () {
    Http::fake(['*' => Http::response(PNG.'x', 200)]);

    // fanart and video carry no region at all, so there is nothing to choose.
    $entry = entry('ss');
    unset($entry['region']);

    runScrape($this->game, [$entry]);

    expect(Media::count())->toBe(1)
        ->and(Media::sole()->region)->toBeNull();
});

it('fetches a world-only screenshot whatever region was asked for, but no other country\'s cover', function () {
    Http::fake([
        '*media=box-2D*' => Http::response(PNG.'cover', 200),
        '*media=ss*' => Http::response(PNG.'shot', 200),
    ]);

    // The screenshot exists only for the world, as nearly every one does; the
    // cover exists for Japan alone, so a United States fetch has none to take.
    (new ScrapeGameMedia($this->game->id, [
        entry('box-2D', 'a', region: 'jp'),
        entry('ss', 'b', region: 'wor'),
    ], region: 'us'))->handle(app(ScreenScraperService::class), app(MediaLibrary::class));

    expect(Media::sole())
        ->screenscraper_type->toBe('ss')
        ->region->toBe('wor');
});

it('keeps one of each type, not one overall', function () {
    // Distinct bytes per type: identical content is deduplicated on md5, which
    // would otherwise hide the second type rather than prove it was fetched.
    Http::fake([
        '*media=box-2D*' => Http::response(PNG.'cover', 200),
        '*media=ss*' => Http::response(PNG.'shot', 200),
    ]);

    runScrape($this->game, [
        entry('box-2D', 'a', region: 'us'),
        entry('box-2D', 'b', region: 'eu'),
        entry('ss', 'c', region: 'us'),
        entry('ss', 'd', region: 'eu'),
    ]);

    expect(Media::pluck('screenscraper_type')->sort()->values()->all())->toBe(['box-2D', 'ss']);
});

/*
 * The kept list. One jeuInfos answer names the game, carries its rating and
 * lists its artwork; keeping the list is what lets everything after the match
 * choose artwork again without paying for that answer twice.
 */

function jeuInfos(array $medias): array
{
    return ['response' => ['jeu' => [
        'id' => '19256',
        'noms' => [['region' => 'ss', 'text' => 'Final Fantasy IX']],
        'note' => ['text' => '17'],
        'medias' => $medias,
    ]]];
}

it('keeps the artwork list from the answer that identified the game', function () {
    Bus::fake();
    Http::fake(['*' => Http::response(jeuInfos([entry('box-2D'), entry('ss')]), 200)]);

    $game = Game::factory()->forConsole('psx')->create();
    GameFile::factory()->for($game)->create(['path' => 'psx/a.bin', 'filename' => 'a.bin', 'extension' => 'bin', 'role' => FileRole::Track]);

    (new MatchGame($game->id))->handle(app(GameMatcher::class));

    // The survivor of the merge into the game beforeEach made, which holds the
    // same provider id — the list follows the identity.
    $kept = $this->game->refresh()->mediaList;

    expect($kept)->not->toBeNull()
        ->and(array_column($kept->medias, 'type'))->toBe(['box-2D', 'ss'])
        ->and($this->game->rating)->toBe(85);

    Http::assertSentCount(1);
});

it('chooses from the kept list without asking the provider again', function () {
    $cover = PNG.'cover';
    $this->game->rememberMediaList([entry('box-2D', $cover)]);

    Http::fake([
        '*jeuInfos*' => Http::response(jeuInfos([]), 200),
        '*mediaJeu*' => Http::response($cover, 200),
    ]);

    (new ScrapeGameMedia($this->game->id))->handle(app(ScreenScraperService::class), app(MediaLibrary::class));

    expect(Media::count())->toBe(1);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'jeuInfos'));
});

it('asks once for a game identified before lists were kept, and keeps the answer', function () {
    $cover = PNG.'cover';

    Http::fake([
        '*jeuInfos*' => Http::response(jeuInfos([entry('box-2D', $cover)]), 200),
        '*mediaJeu*' => Http::response($cover, 200),
    ]);

    (new ScrapeGameMedia($this->game->id))->handle(app(ScreenScraperService::class), app(MediaLibrary::class));

    expect($this->game->refresh()->mediaList?->medias)->toHaveCount(1)
        ->and(Media::count())->toBe(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'jeuInfos'));
});

it('renews the list when asked to, rather than trusting the kept one', function () {
    $shot = PNG.'shot';
    $this->game->rememberMediaList([entry('box-2D')]);

    Http::fake([
        '*jeuInfos*' => Http::response(jeuInfos([entry('ss', $shot)]), 200),
        '*mediaJeu*' => Http::response($shot, 200),
    ]);

    (new ScrapeGameMedia($this->game->id, fresh: true))->handle(app(ScreenScraperService::class), app(MediaLibrary::class));

    expect(array_column($this->game->refresh()->mediaList->medias, 'type'))->toBe(['ss'])
        ->and(Media::sole()->screenscraper_type)->toBe('ss');
});

it('fills gaps from the kept list and renews it only for re-fetch all', function () {
    Bus::fake();

    ScrapeGameMedia::queueForConsole('psx');
    Bus::assertDispatched(ScrapeGameMedia::class, fn (ScrapeGameMedia $job): bool => ! $job->fresh);

    Bus::fake();

    ScrapeGameMedia::queueForConsole('psx', held: true);
    Bus::assertDispatched(ScrapeGameMedia::class, fn (ScrapeGameMedia $job): bool => $job->fresh);
});

it('fills a type switched on after the game already had artwork', function () {
    Bus::fake();

    // Screenshots were off when the cover came; the kept list offers one.
    Media::factory()->for($this->game)->ofType('box-2D', 'us')->create();
    $this->game->rememberMediaList([entry('box-2D'), entry('ss', region: 'wor')]);

    expect(ScrapeGameMedia::queueForConsole('psx'))->toBe(1);
    Bus::assertDispatched(ScrapeGameMedia::class, fn (ScrapeGameMedia $job): bool => $job->gameId === $this->game->id && ! $job->fresh);
});

it('leaves a game alone when the provider has nothing more to give it', function () {
    Bus::fake();

    // ss is switched on, but this game has none at the provider: asking again
    // would come back with nothing, every time.
    Media::factory()->for($this->game)->ofType('box-2D', 'us')->create();
    $this->game->rememberMediaList([entry('box-2D'), entry('video')]);

    expect(ScrapeGameMedia::queueForConsole('psx'))->toBe(0);
    Bus::assertNotDispatched(ScrapeGameMedia::class);
});

it('keeps the list from a rating fetch too', function () {
    Http::fake(['*' => Http::response(jeuInfos([entry('box-2D')]), 200)]);

    (new RateGame($this->game->id))->handle(app(ScreenScraperService::class));

    $this->game->refresh();

    expect($this->game->rating)->toBe(85)
        ->and(array_column($this->game->mediaList->medias, 'type'))->toBe(['box-2D']);
});

it('still runs a job queued before the list was kept', function () {
    // A job is unserialised, not constructed. One serialised before $fresh
    // existed carries no value for it, and reading an uninitialised property
    // is fatal — which is what took the whole waiting artwork queue down.
    $cover = PNG.'cover';
    $this->game->rememberMediaList([entry('box-2D', $cover)]);

    // A payload as the release before this one queued it, byte for byte:
    // no fresh at all.
    $old = sprintf(
        'O:24:"App\\Jobs\\ScrapeGameMedia":4:{s:6:"gameId";i:%d;s:6:"medias";N;s:6:"region";N;s:5:"queue";s:5:"media";}',
        $this->game->id,
    );

    Http::fake(['*mediaJeu*' => Http::response($cover, 200)]);

    unserialize($old)->handle(app(ScreenScraperService::class), app(MediaLibrary::class));

    expect(Media::count())->toBe(1);
});
