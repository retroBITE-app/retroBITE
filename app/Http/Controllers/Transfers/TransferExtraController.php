<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfers;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Transfers\TransferTargets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One of a target's own files for a game — an OPL config or a piece of its
 * art — made now, for the browser to write to the drive. Only a destination
 * the target lists for the game is made; nothing is read from disk by path.
 */
class TransferExtraController extends Controller
{
    public function __invoke(Request $request, string $target, int $gameId, string $path): Response
    {
        $transferTarget = TransferTargets::requested($target, $request);
        abort_if($transferTarget === null, 404);

        $game = Game::query()->with(['files', 'media'])->findOrFail($gameId);
        $bytes = $transferTarget->extra($game, $path);

        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
