<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfers;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Transfers\TransferRejected;
use App\Transfers\TransferTargets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a transfer of one game to one target writes, for the browser to copy.
 */
class TransferPlanController extends Controller
{
    public function __invoke(Request $request, string $target, int $gameId): JsonResponse
    {
        $transferTarget = TransferTargets::requested($target, $request);
        abort_if($transferTarget === null, 404);

        $game = Game::query()->findOrFail($gameId);

        try {
            return response()->json($transferTarget->plan($game)->toArray());
        } catch (TransferRejected $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
