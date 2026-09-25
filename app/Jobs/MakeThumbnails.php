<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MediaKind;
use App\Enums\ThumbnailSize;
use App\Events\GameUpdated;
use App\Models\Media;
use App\Support\LiveUpdates;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Write the list and grid copies of one downloaded cover.
 *
 * The originals are whatever the provider had — often several megabytes and
 * two thousand pixels across — and the interface draws them at 48 CSS pixels
 * in the list and at most 280 on the shelf. Each size is scaled down to fit
 * its box, keeping its shape and never enlarged, and written as WebP beside
 * the rest of the artwork. The original is kept: the game page's viewer shows
 * it in full, and a thumbnail can always be made again from it.
 *
 * Covers only. Logos, backdrops and screenshots are drawn large or not at all.
 *
 * On a queue of its own, `thumbnails`, with its own workers
 * (QUEUE_WORKERS_THUMBNAILS): resizing is CPU work, and sharing the media
 * queue put a library's worth of it in front of the downloads. A cover that
 * cannot be decoded is logged and left without thumbnails, which only means
 * the interface keeps showing the original.
 */
class MakeThumbnails implements ShouldQueue
{
    use Queueable;

    /** Under the default connection's retry_after of 90. */
    public int $timeout = 60;

    public int $tries = 2;

    /** WebP quality. High enough that box art text stays legible. */
    private const QUALITY = 80;

    public function __construct(public readonly int $mediaId)
    {
        $this->onQueue('thumbnails');
    }

    /** Whether a media row is artwork this job makes thumbnails for. */
    public static function covers(Media $media): bool
    {
        return in_array($media->screenscraper_type, MediaKind::Cover->screenScraperTypes(), true);
    }

    public function handle(): void
    {
        $media = Media::find($this->mediaId);

        // Replaced by a newer copy since this was queued, or not a cover.
        if ($media === null || ! self::covers($media)) {
            return;
        }

        $disk = Storage::disk('media');

        if (! $disk->exists($media->path)) {
            return;
        }

        $paths = [];

        try {
            $source = Image::fromStorage($media->path, disk: 'media');

            foreach (ThumbnailSize::cases() as $size) {
                $path = self::pathFor($media, $size);

                $source->scale($size->box(), $size->box())
                    ->toWebp()
                    ->quality(self::QUALITY)
                    ->storeAs(dirname($path), basename($path), 'media');

                $paths[$size->column()] = $path;
            }
        } catch (Throwable $e) {
            Log::warning('Could not make thumbnails for a cover.', [
                'media' => $media->id,
                'path' => $media->path,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        $media->update($paths);

        // A game page open on this game swaps the original for the copy.
        LiveUpdates::game($media->game_id, GameUpdated::ARTWORK);
    }

    /**
     * Where one size of a cover goes: under thumbnails/{size}/, mirroring the
     * original's own path, so the two are easy to match by eye on the disk.
     */
    public static function pathFor(Media $media, ThumbnailSize $size): string
    {
        $original = $media->path;
        $stem = substr($original, 0, (int) strrpos($original, '.')) ?: $original;

        return 'thumbnails/'.$size->value.'/'.$stem.'.webp';
    }
}
