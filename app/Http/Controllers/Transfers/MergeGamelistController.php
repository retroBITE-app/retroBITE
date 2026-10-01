<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfers;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Transfers\TransferRejected;
use App\Transfers\TransferTargets;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Take the game list the browser read off the drive, and hand back the same
 * list with this game's entry in it. The browser writes the answer back.
 *
 * The body is the file as it is, or empty when the drive has none yet.
 */
class MergeGamelistController extends Controller
{
    public function __invoke(Request $request, string $target, int $gameId): Response
    {
        $transferTarget = TransferTargets::requested($target, $request);
        abort_if($transferTarget === null, 404);

        $game = Game::query()->findOrFail($gameId);
        $existing = $request->getContent();

        try {
            $merged = $transferTarget->mergeGamelist($existing !== '' ? $existing : null, $game);
        } catch (TransferRejected $e) {
            return response($e->getMessage(), 422, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return response($merged, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
