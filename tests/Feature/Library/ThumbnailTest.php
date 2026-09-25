<?php

use App\Enums\ThumbnailSize;
use App\Events\GameUpdated;
use App\Jobs\MakeThumbnails;
use App\Models\Game;
use App\Models\Media;
use App\Models\User;
use App\Services\MediaLibrary;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Covers are shown at a fraction of their size, from copies made in the
 * background: 128 px for the list view, 560 px for the shelf. The images here
 * are real PNGs drawn with GD, so the sizes asserted are what the driver made.
 */

beforeEach(function () {
    Storage::fake('media');
    $this->actingAs(User::factory()->create());
});

/** A real PNG of the given size. */
function pngSized(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** A cover on the fake disk, with its row. */
function coverOf(int $width, int $height, string $type = 'box-2D'): Media
{
    $game = Game::factory()->forConsole('snes')->matched()->create();
    $media = Media::factory()->for($game)->ofType($type, 'eu')->create(['path' => 'snes/game/'.$type.'/abc.png']);
    Storage::disk('media')->put($media->path, pngSized($width, $height));

    return $media;
}

it('makes a list and a grid copy, each fitted to its box', function () {
    $media = coverOf(2100, 1470);

    (new MakeThumbnails($media->id))->handle();
    $media->refresh();

    $list = Image::fromStorage($media->thumbnail_list_path, disk: 'media');
    $grid = Image::fromStorage($media->thumbnail_grid_path, disk: 'media');

    // Wider than tall, so the width meets the box first and the shape is kept.
    expect($list->dimensions())->toBe([128, 90])
        ->and($grid->dimensions())->toBe([560, 392])
        ->and($list->mimeType())->toBe('image/webp')
        ->and($media->thumbnail_list_path)->toBe('thumbnails/list/snes/game/box-2D/abc.webp');
});

it('fits a tall cover by its height', function () {
    $media = coverOf(700, 1000);

    (new MakeThumbnails($media->id))->handle();

    expect(Image::fromStorage($media->refresh()->thumbnail_list_path, disk: 'media')->dimensions())->toBe([90, 128]);
});

it('never enlarges a cover smaller than the box', function () {
    $media = coverOf(100, 60);

    (new MakeThumbnails($media->id))->handle();

    expect(Image::fromStorage($media->refresh()->thumbnail_list_path, disk: 'media')->dimensions())->toBe([100, 60]);
});

it('queues thumbnails for a downloaded cover, and nothing for other artwork', function () {
    Bus::fake();
    $game = Game::factory()->forConsole('snes')->matched()->create();

    $cover = app(MediaLibrary::class)->store($game, ['type' => 'box-2D', 'region' => 'eu'], pngSized(300, 400));
    app(MediaLibrary::class)->store($game, ['type' => 'wheel', 'region' => 'eu'], pngSized(301, 100));

    Bus::assertDispatchedTimes(MakeThumbnails::class, 1);
    Bus::assertDispatched(MakeThumbnails::class, fn (MakeThumbnails $job): bool => $job->mediaId === $cover->id);
});

it('leaves a cover it cannot read without thumbnails, rather than failing', function () {
    $game = Game::factory()->forConsole('snes')->matched()->create();
    $media = Media::factory()->for($game)->ofType('box-2D', 'eu')->create(['path' => 'snes/game/box-2d/bad.png']);
    Storage::disk('media')->put($media->path, 'not an image');

    (new MakeThumbnails($media->id))->handle();

    expect($media->refresh()->thumbnail_list_path)->toBeNull()
        // The interface falls back to the original.
        ->and($media->url(ThumbnailSize::Grid))->toBe(route('media.show', ['path' => $media->path]));
});

it('removes the thumbnails with the cover', function () {
    $media = coverOf(600, 800);
    (new MakeThumbnails($media->id))->handle();
    $media->refresh();

    app(MediaLibrary::class)->forget($media);

    Storage::disk('media')->assertMissing($media->path);
    Storage::disk('media')->assertMissing($media->thumbnail_list_path);
    Storage::disk('media')->assertMissing($media->thumbnail_grid_path);
});

it('serves a thumbnail by its own path, and nothing that is not a known file', function () {
    $media = coverOf(600, 800);
    (new MakeThumbnails($media->id))->handle();
    $media->refresh();

    $this->get(route('media.show', ['path' => $media->thumbnail_grid_path]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');

    Storage::disk('media')->put('thumbnails/list/stray.webp', 'x');
    $this->get(route('media.show', ['path' => 'thumbnails/list/stray.webp']))->assertNotFound();
});

it('shows the shelf the grid copy and the list the list copy', function () {
    $media = coverOf(600, 800);
    (new MakeThumbnails($media->id))->handle();
    $media->refresh();

    Livewire::test('games.index', ['console' => 'snes'])
        ->assertSee(route('media.show', ['path' => $media->thumbnail_grid_path]), escape: false)
        ->assertDontSee(route('media.show', ['path' => $media->path]), escape: false);

    Livewire::withQueryParams(['view' => 'table'])->test('games.index', ['console' => 'snes'])
        ->assertSee(route('media.show', ['path' => $media->thumbnail_list_path]), escape: false);
});

it('backfills covers downloaded before thumbnails existed', function () {
    $media = coverOf(600, 800);

    $this->artisan('retrobite:thumbnails')->assertSuccessful();

    expect($media->refresh()->thumbnail_grid_path)->not->toBeNull();

    $this->artisan('retrobite:thumbnails')->expectsOutputToContain('already has its thumbnails');
});

it('runs on a queue of its own and tells an open game page when it is done', function () {
    Event::fake([GameUpdated::class]);
    $media = coverOf(600, 800);

    expect((new MakeThumbnails($media->id))->queue)->toBe('thumbnails');

    (new MakeThumbnails($media->id))->handle();

    Event::assertDispatched(
        GameUpdated::class,
        fn (GameUpdated $e): bool => $e->gameId === $media->game_id && $e->what === GameUpdated::ARTWORK,
    );
});

it('fills in artwork that arrives while the game page is open', function () {
    // Downloaded somewhere else — after an identification, from the console's
    // menu — with nothing on this page waiting for it.
    $game = Game::factory()->forConsole('snes')->matched()->create(['title' => 'Late Art', 'slug' => 'late-art']);

    $page = Livewire::test('games.show', ['game' => $game])
        ->assertSeeHtml("['artwork', 'reconnect'].includes(what)");

    $media = Media::factory()->for($game)->ofType('box-2D', 'eu')->create(['path' => 'snes/late-art/box-2d/new.png']);
    Storage::disk('media')->put($media->path, pngSized(300, 400));

    $page->call('artworkChanged')
        ->assertSee(route('media.show', ['path' => $media->path]), escape: false);
});
