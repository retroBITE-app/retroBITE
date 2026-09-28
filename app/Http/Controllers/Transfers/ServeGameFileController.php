<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfers;

use App\Http\Controllers\Controller;
use App\Models\GameFile;
use App\Support\Scanning\LibraryFolders;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Hand one of a game's files to the browser, for a transfer to copy.
 *
 * Read, never written: the library is served as it is. Only a file a row
 * points at can be asked for, by its id, so there is no path to traverse.
 * A BinaryFileResponse rather than reading into memory, because a disc image
 * is four gigabytes.
 */
class ServeGameFileController extends Controller
{
    public function __invoke(int $file): BinaryFileResponse
    {
        $gameFile = GameFile::query()->present()->findOrFail($file);

        $path = LibraryFolders::root().'/'.$gameFile->path;

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
