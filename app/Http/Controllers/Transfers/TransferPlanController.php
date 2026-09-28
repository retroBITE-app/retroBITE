<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfers;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Transfers\TransferRejected;
use App\Transfers\TransferTargets;
use Illuminate\Http\JsonResponse;

/**
 * What a transfer of one game to one target writes, for the browser to copy.
 */
class TransferPlanController extends Controller
{
    public function __invoke(string $target, int $gameId): JsonResponse
    {
        $transferTarget = TransferTargets::find($target);
        abort_if($transferTarget === null, 404);

        $game = Game::query()->findOrFail($gameId);

        try {
            return response()->json($transferTarget->plan($game)->toArray());
        } catch (TransferRejected $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
