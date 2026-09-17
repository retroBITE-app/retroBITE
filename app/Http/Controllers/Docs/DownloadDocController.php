<?php

namespace App\Http\Controllers\Docs;

use App\Exceptions\DocPathException;
use App\Http\Controllers\Controller;
use App\Support\DocPath;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;


final class DownloadDocController extends Controller
{
    public function __construct(private readonly DocPath $paths) {}

    public function __invoke(string $path): StreamedResponse
    {
        try {
            $relative = $this->paths->assertDocument($path);
        } catch (DocPathException $e) {
            Log::warning('Refused a docs download path', ['reason' => $e->reason, 'path' => $e->path]);

            abort(404);
        }

        $disk = Storage::disk('docs');

        abort_unless($disk->exists($relative), 404);

        return $disk->download($relative, (string) Str::afterLast($relative, '/'));
    }
}
