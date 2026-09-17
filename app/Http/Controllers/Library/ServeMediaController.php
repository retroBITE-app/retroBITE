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
 * instead of a string-matching exercise.
 */
class ServeMediaController extends Controller
{
    public function __invoke(Request $request, string $path): Response
    {
        $media = Media::query()->where('path', $path)->first();

        abort_if($media === null, 404);

        $disk = Storage::disk('media');

        abort_unless($disk->exists($media->path), 404);

        return new StreamedResponse(
            fn () => fpassthru($disk->readStream($media->path)),
            200,
            [
                'Content-Type' => $disk->mimeType($media->path) ?: 'application/octet-stream',
                'Content-Length' => (string) $disk->size($media->path),
                // Content-addressed: the filename is the checksum, so a URL
                // can never point at different bytes than it did before.
                'Cache-Control' => 'private, max-age=31536000, immutable',
            ],
        );
    }
}
