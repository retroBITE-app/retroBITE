<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfers;

use App\Http\Controllers\Controller;
use App\Support\Console;
use App\Transfers\ConsoleTransfer;
use App\Transfers\TransferRejected;
use App\Transfers\TransferTargets;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The console's game list off the drive, handed back with an entry for every
 * game the console plan sends — merged in one pass, not one game at a time.
 *
 * The body is the file as it is, or empty when the drive has none yet.
 */
class MergeConsoleGamelistController extends Controller
{
    public function __invoke(Request $request, string $target, string $console): Response
    {
        $transferTarget = TransferTargets::requested($target, $request);
        abort_if($transferTarget === null || Console::tryFrom($console) === null, 404);

        ['games' => $games] = ConsoleTransfer::plan($transferTarget, $console);
        $existing = $request->getContent();

        try {
            $merged = $transferTarget->mergeGamelist($existing !== '' ? $existing : null, ...$games);
        } catch (TransferRejected $e) {
            return response($e->getMessage(), 422, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return response($merged, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
