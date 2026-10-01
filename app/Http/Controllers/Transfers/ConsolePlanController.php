<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfers;

use App\Http\Controllers\Controller;
use App\Support\Console;
use App\Transfers\ConsoleTransfer;
use App\Transfers\TransferTargets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What sending a whole console to one target writes, for the browser to copy
 * onto a drive: every identified game, one version each. The same shape as a
 * game's plan, so the drive is written the same way.
 */
class ConsolePlanController extends Controller
{
    public function __invoke(Request $request, string $target, string $console): JsonResponse
    {
        $transferTarget = TransferTargets::requested($target, $request);
        abort_if($transferTarget === null || Console::tryFrom($console) === null, 404);

        ['plan' => $plan, 'rejected' => $rejected] = ConsoleTransfer::plan($transferTarget, $console);

        return response()->json($plan->toArray() + ['rejected' => $rejected]);
    }
}
