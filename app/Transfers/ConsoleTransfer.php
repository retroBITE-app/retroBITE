<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Enums\GameStatus;
use App\Models\Game;
use Illuminate\Database\Eloquent\Collection;

/**
 * A whole console sent at once: every identified game with a file on disk,
 * one version each (see GameVersions), under one game list.
 *
 * Not the console's files. A placeholder has no name to list it under and an
 * unmatched game no artwork, so neither goes; and a game holding three
 * regions goes once.
 */
final class ConsoleTransfer
{
    /**
     * The games that go, loaded with everything a plan reads.
     *
     * @return Collection<int, Game>
     */
    public static function games(string $console): Collection
    {
        return Game::query()
            ->forConsole($console)
            ->where('status', GameStatus::Matched)
            ->whereHas('files', fn ($query) => $query->present())
            ->with(['files', 'media'])
            ->orderBy('title')
            ->get();
    }

    /**
     * The plan for every game that can go, and how many could not.
     *
     * A game that cannot be planned — its files outside the console's folder —
     * is left out rather than failing the rest.
     *
     * @return array{plan: TransferPlan, games: list<Game>, plans: array<int, TransferPlan>, rejected: int}
     */
    public static function plan(TransferTarget $target, string $console): array
    {
        $plans = [];
        $games = [];
        $rejected = 0;
        $gamelist = null;

        foreach (self::games($console) as $game) {
            try {
                $plan = $target->plan($game);
            } catch (TransferRejected) {
                $rejected++;

                continue;
            }

            if ($plan->files === []) {
                continue;
            }

            $plans[$game->id] = $plan;
            $games[] = $game;
            $gamelist ??= $plan->gamelist;
        }

        return [
            'plan' => TransferPlan::combine(array_values($plans), $gamelist),
            'games' => $games,
            'plans' => $plans,
            'rejected' => $rejected,
        ];
    }
}
