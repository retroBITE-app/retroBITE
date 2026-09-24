<?php

declare(strict_types=1);

namespace App\Http\Controllers\Library;

use App\Enums\UploadRejection;
use App\Exceptions\UploadRejected;
use App\Http\Controllers\Controller;
use App\Services\RomUploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Take one chunk of a ROM upload.
 *
 * A plain route rather than a Livewire call because the body is raw bytes:
 * Livewire would carry them as JSON, and a hundred-megabyte string in a
 * payload is the thing chunking exists to avoid. Everything else about the
 * upload — whether it may happen, where it goes, the move into place — is the
 * upload modal's, and this only ever names the upload by its id.
 */
class UploadChunkController extends Controller
{
    public function __invoke(Request $request, RomUploads $uploads, string $upload): JsonResponse
    {
        $offset = $request->header('X-Upload-Offset');

        if (! is_string($offset) || ! ctype_digit($offset)) {
            return $this->rejected(UploadRejected::because(UploadRejection::Incomplete));
        }

        if ((int) $request->header('Content-Length', '0') > RomUploads::CHUNK_BYTES) {
            return $this->rejected(UploadRejected::because(UploadRejection::ChunkTooLarge));
        }

        $body = $request->getContent(true);

        if (! is_resource($body)) {
            return $this->rejected(UploadRejected::because(UploadRejection::Unwritable));
        }

        try {
            $received = $uploads->append($upload, (int) $request->user()?->getAuthIdentifier(), (int) $offset, $body);
        } catch (UploadRejected $e) {
            return $this->rejected($e);
        }

        return response()->json(['received' => $received]);
    }

    /**
     * A refusal as the uploader reads it: 409 carries where to resume from.
     */
    private function rejected(UploadRejected $e): JsonResponse
    {
        return match ($e->reason) {
            UploadRejection::Unknown => response()->json(['message' => $e->reason->label()], 404),
            UploadRejection::OutOfOrder => response()->json(['message' => $e->reason->label(), 'received' => $e->received], 409),
            UploadRejection::ChunkTooLarge => response()->json(['message' => $e->reason->label()], 413),
            default => response()->json(['message' => $e->reason->label()], 422),
        };
    }
}
