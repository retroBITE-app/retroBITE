<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfers;

use App\Http\Controllers\Controller;
use App\Models\GameFile;
use App\Support\NginxFile;
use App\Support\Scanning\LibraryFolders;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hand one of a game's files to the browser, for a transfer to copy.
 *
 * Read, never written: the library is served as it is. Only a file a row
 * points at can be asked for, by its id, so there is no path to traverse.
 * Sent by nginx where it is in front (NginxFile), else as a BinaryFileResponse —
 * never read into memory, because a disc image is four gigabytes.
 */
class ServeGameFileController extends Controller
{
    public function __invoke(int $file): Response
    {
        $gameFile = GameFile::query()->present()->findOrFail($file);

        $path = LibraryFolders::pathOf($gameFile);

        abort_unless(is_file($path), 404);

        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
        ];

        if (NginxFile::enabled()) {
            return NginxFile::response('/_serve/games', $gameFile->path, $headers);
        }

        return response()->file($path, $headers);
    }
}
