<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One random piece of key art, for the login page's backdrop.
 *
 * The media disk sits outside public/ and ServeMediaController is behind auth,
 * which a sign-in page cannot be. This is the narrow way through: it takes no
 * path, so nobody can ask it for a particular file or enumerate the library —
 * it answers with a backdrop of its own choosing, or with nothing.
 */
class LoginBackdropController extends Controller
{
    public function __invoke(): Response
    {
        $media = Media::query()->wallpaper()->inRandomOrder()->first();

        abort_if($media === null, 404);

        $disk = Storage::disk('media');

        abort_unless($disk->exists($media->path), 404);

        return new StreamedResponse(
            fn () => fpassthru($disk->readStream($media->path)),
            200,
            [
                'Content-Type' => $disk->mimeType($media->path) ?: 'application/octet-stream',
                'Content-Length' => (string) $disk->size($media->path),
                // Never cached: the point of the route is that a reload brings a
                // different game's artwork.
                'Cache-Control' => 'no-store, max-age=0',
            ],
        );
    }
}
