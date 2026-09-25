<?php

declare(strict_types=1);

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve one piece of downloaded artwork.
 *
 * The media disk sits outside public/ on purpose, so this route is the only
 * way in and it is behind auth. The path is checked against the media table
 * rather than sanitised: only a file some row actually points at can be
 * served, which makes traversal a question of what exists in the database
 * instead of a string-matching exercise. A cover's thumbnails are served the
 * same way, found by their own columns on the row they belong to.
 */
class ServeMediaController extends Controller
{
    public function __invoke(Request $request, string $path): Response
    {
        $known = Media::query()
            ->where('path', $path)
            ->orWhere('thumbnail_list_path', $path)
            ->orWhere('thumbnail_grid_path', $path)
            ->exists();

        abort_unless($known, 404);

        $disk = Storage::disk('media');

        abort_unless($disk->exists($path), 404);

        return new StreamedResponse(
            fn () => fpassthru($disk->readStream($path)),
            200,
            [
                'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
                'Content-Length' => (string) $disk->size($path),
                // Content-addressed: the filename is the checksum, so a URL
                // can never point at different bytes than it did before.
                'Cache-Control' => 'private, max-age=31536000, immutable',
            ],
        );
    }
}
