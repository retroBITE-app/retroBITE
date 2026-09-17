<?php

namespace App\Http\Controllers\Docs;

use App\Exceptions\DocPathException;
use App\Http\Controllers\Controller;
use App\Support\DocPath;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ServeMediaController extends Controller
{
    /** Browsers may hold an attachment for a day; the filename is stable. */
    private const MAX_AGE = 86400;

    public function __construct(private readonly DocPath $paths) {}

    public function __invoke(string $path): StreamedResponse
    {
        try {
            $relative = $this->paths->assertMedia($path);
        } catch (DocPathException $e) {
            Log::warning('Refused a docs media path', ['reason' => $e->reason, 'path' => $e->path]);
            abort(404);
        }

        $disk = Storage::disk('docs');

        abort_unless($disk->exists($relative), 404);

        return $disk->response($relative, headers: [
            'Cache-Control' => 'private, max-age='.self::MAX_AGE,
            'Content-Disposition' => 'inline',
        ]);
    }
}
