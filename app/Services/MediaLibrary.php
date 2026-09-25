<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\MakeThumbnails;
use App\Models\Game;
use App\Models\Media;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where downloaded artwork lives, and how it stops existing.
 *
 * Every file here was fetched by retroBite and is owned by it, which is the
 * whole difference from a GameFile: this can be deleted and fetched again
 * without losing anything, so nothing needs to be careful with it.
 */
final class MediaLibrary
{
    public function disk(): Filesystem
    {
        return Storage::disk('media');
    }

    /**
     * Save one downloaded file against a game.
     *
     * Returns null when the game already holds this exact image — the unique
     * index is on content, so the same image twice is not allowed.
     *
     * Either way the game is left holding one copy of the type: see
     * keepOne(), which is what makes a second scrape replace rather than
     * accumulate.
     *
     * @param  array<string, mixed>  $entry  one item from the provider's media list
     */
    public function store(Game $game, array $entry, string $contents): ?Media
    {
        // The checksum of what actually arrived, not what the metadata claimed
        // it would be. They disagree often enough to matter.
        $md5 = md5($contents);
        $type = (string) ($entry['type'] ?? 'unknown');

        if ($game->media()->where('md5', $md5)->exists()) {
            $this->keepOne($game, $type, $entry['region'] ?? null, $md5);

            return null;
        }

        $extension = $this->extensionFor($entry, $contents);
        $path = $this->pathFor($game, $type, $md5, $extension);

        $this->disk()->put($path, $contents);

        $media = $game->media()->create([
            'screenscraper_type' => $type,
            'region' => $entry['region'] ?? null,
            'md5' => $md5,
            'path' => $path,
            'extension' => $extension,
            'size_bytes' => strlen($contents),
            'source_url' => $entry['url'] ?? null,
            'downloaded_at' => now(),
        ]);

        $this->keepOne($game, $type, $entry['region'] ?? null, $md5);

        // The interface shows covers at a fraction of their size, from copies
        // made in the background; until they exist it shows this original.
        if (MakeThumbnails::covers($media)) {
            MakeThumbnails::dispatch($media->id);
        }

        return $media;
    }

    /**
     * Leave the game holding this copy of the type from this region, no other.
     *
     * One slot per type *and* region, not per type: a game may hold the same
     * cover from four regions at once, which is what makes choosing between
     * them on the game page possible. What it may not hold is two European
     * covers, which is what a provider revising its artwork would otherwise
     * leave behind every time.
     *
     * Does nothing unless the copy to keep is really there, which makes it
     * safe to call on a skipped download: an md5 held under another type is
     * not this type's answer and takes nothing with it.
     *
     * @return int how many were dropped
     */
    public function keepOne(Game $game, string $type, ?string $region, string $md5): int
    {
        $ofSlot = fn () => $game->media()
            ->where('screenscraper_type', $type)
            ->when($region === null, fn ($query) => $query->whereNull('region'))
            ->when($region !== null, fn ($query) => $query->where('region', $region));

        $keeper = $ofSlot()->where('md5', $md5)->first();

        if ($keeper === null) {
            return 0;
        }

        $stale = $ofSlot()->whereKeyNot($keeper->getKey())->get();

        foreach ($stale as $media) {
            $this->forget($media);
        }

        // The loaded collection still holds what was just deleted, and the
        // page that asked for the scrape reads it.
        $game->unsetRelation('media');

        return $stale->count();
    }

    /**
     * Remove a media row and the file behind it, together.
     *
     * Always together: a row deleted on its own leaks the file, and a file
     * deleted on its own leaves a row pointing at nothing. The thumbnails go
     * with it, being nothing but smaller copies of the same file.
     */
    public function forget(Media $media): void
    {
        $this->disk()->delete($media->files());
        $media->delete();
    }

    /** Remove everything a game holds. */
    public function forgetAll(Game $game): int
    {
        $count = 0;

        foreach ($game->media as $media) {
            $this->forget($media);
            $count++;
        }

        return $count;
    }

    /**
     * Where a file goes.
     *
     * The provider id joins the slug because titles are not unique — remakes
     * and re-releases share one — while the id is unique per game. It is
     * always known here: artwork is only fetched after a game is identified.
     */
    private function pathFor(Game $game, string $type, string $md5, string $extension): string
    {
        $folder = $game->screenscraper_id !== null
            ? $game->slug.'-'.$game->screenscraper_id
            : $game->slug.'-'.$game->id;

        return implode('/', [
            $game->console,
            $folder,
            Str::slug($type) ?: 'other',
            $md5.'.'.$extension,
        ]);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function extensionFor(array $entry, string $contents): string
    {
        $format = strtolower((string) ($entry['format'] ?? ''));

        if ($format !== '' && preg_match('/^[a-z0-9]{2,5}$/', $format) === 1) {
            return $format;
        }

        // The provider omits format on some entries; the bytes still say what
        // they are.
        return match (true) {
            str_starts_with($contents, "\x89PNG") => 'png',
            str_starts_with($contents, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($contents, 'GIF8') => 'gif',
            str_starts_with($contents, '%PDF') => 'pdf',
            default => 'bin',
        };
    }
}
