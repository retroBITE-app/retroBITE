<?php

use App\Enums\FileRole;
use App\Jobs\MatchGame;
use App\Jobs\ScrapeGameMedia;
use App\Models\AppSetting;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Models\MediaTypePreference;
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

    foreach (['box-2D', 'ss'] as $type) {
        MediaTypePreference::create(['media_type' => $type, 'enabled' => true]);
    }
    MediaTypePreference::create(['media_type' => 'video', 'enabled' => false]);

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

it('treats NOMEDIA as an ordinary answer, not a failure', function () {
    Http::fake(['*' => Http::response('NOMEDIA', 200)]);

    runScrape($this->game, [entry('box-2D')]);

    expect(Media::count())->toBe(0);
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

    expect(Media::count())->toBe(2);
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
        ->and($activity->subject->is($this->game))->toBeTrue();
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
