<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\NotFoundException;
use App\Models\Game;
use App\Repositories\GameRepository;
use App\Support\Console;
use App\Support\GameId;
use Illuminate\Support\Arr;

/**
 * Turns Slim's route arguments into domain objects, throwing NotFoundException
 * rather than making every controller method repeat the lookup and its guard.
 */
class RouteResolver
{
    public function __construct(
        private GameRepository $games,
    ) {}

    /**
     * The console named by the `{console}` route argument.
     *
     * @throws NotFoundException
     */
    public function console(array $args): Console
    {
        $key     = Arr::get($args, 'console');
        $console = Console::tryFrom($key);

        if ($console === null) {
            throw NotFoundException::console(is_string($key) ? $key : null);
        }

        return $console;
    }

    /**
     * The game named by the `{game}` route argument, within a resolved console.
     *
     * @throws NotFoundException
     */
    public function game(Console $console, array $args): Game
    {
        $id   = (string) GameId::for($console, Arr::get($args, 'game'));
        $game = $this->games->find($id);

        if ($game === null) {
            throw NotFoundException::game($id);
        }

        return $game;
    }
}
