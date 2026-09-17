<?php

namespace App\Http\Controllers\Docs;

use App\Http\Controllers\Controller;
use App\Resources\DocResource;
use App\Services\DocArchive;
use App\Services\DocLibrary;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ArchiveDocController extends Controller
{
    public function __construct(
        private readonly DocLibrary $library,
        private readonly DocArchive $archive,
    ) {}

    public function __invoke(string $path): BinaryFileResponse
    {
        abort_unless($this->archive->available(), 404);

        $doc = $this->library->find($path);

        abort_unless($doc instanceof DocResource, 404);

        return response()
            ->download($this->archive->write($doc), $this->archive->filename($doc))
            ->deleteFileAfterSend();
    }
}
