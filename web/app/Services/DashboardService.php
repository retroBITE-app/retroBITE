<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Game;
use App\Repositories\GameRepository;
use App\Support\Console;
use Illuminate\Support\Collection;

/**
 * What the landing page shows about the library: the newest arrival as key art,
 * the ones behind it, the headline figures, and what still needs identifying.
 */
class DashboardService
{
    /** Newest games to fetch before picking a hero from among them. */
    private const RECENT_POOL = 8;

    /** Rows in the column beside the hero. */
    private const RECENT_ROWS = 3;

    /** Rows in the "needs identifying" panel. */
    private const UNMATCHED_ROWS = 5;

    public function __construct(
        private GameRepository $games,
        private GameDataService $gameData,
    ) {}

    /**
     * @return array{hero: ?array<string, mixed>, recent: array<int, array<string, mixed>>, stats: array<int, array{label: string, value: string, sub: string}>, unmatched: array{rows: array<int, array<string, mixed>>, total: int}}
     */
    public function payload(): array
    {
        $pool  = $this->games->recentlyAdded(self::RECENT_POOL);
        $hero  = $this->pickHero($pool);
        $split = $this->games->identificationCounts();

        return [
            'hero'      => $hero === null ? null : $this->row($hero),
            'recent'    => $this->recentRows($pool, $hero),
            'stats'     => $this->stats($split),
            'unmatched' => $this->unmatched($split['unidentified']),
        ];
    }

    /**
     * The newest game with artwork to show, falling back to the newest of any —
     * a hero with no backdrop still reads as a card, an empty library does not.
     *
     * @param Collection<int, Game> $pool
     */
    private function pickHero(Collection $pool): ?Game
    {
        $withArt = $pool->first(
            fn(Game $game) => is_string($game->metadata?->backdrop_url)
                && $game->metadata->backdrop_url !== '',
        );

        return $withArt ?? $pool->first();
    }

    /**
     * The newest games behind the hero.
     *
     * @param Collection<int, Game> $pool
     * @return array<int, array<string, mixed>>
     */
    private function recentRows(Collection $pool, ?Game $hero): array
    {
        return $pool
            ->reject(fn(Game $game) => $hero !== null && $game->id === $hero->id)
            ->take(self::RECENT_ROWS)
            ->map(fn(Game $game) => $this->row($game))
            ->values()
            ->all();
    }

    /**
     * The headline figures. Storage is absent on purpose: the layout already
     * carries it in the shared sidebar prop.
     *
     * @param array{identified: int, unidentified: int} $split
     * @return array<int, array{label: string, value: string, sub: string}>
     */
    private function stats(array $split): array
    {
        $library = $this->games->librarySummary();

        return [
            [
                'label' => 'Consoles',
                'value' => (string) Console::allInstalled()->count(),
                'sub'   => 'installed',
            ],
            [
                'label' => 'Files',
                'value' => number_format($library['game_count']),
                'sub'   => number_format($split['identified']) . ' identified',
            ],
            [
                'label' => 'Unmatched',
                'value' => number_format($split['unidentified']),
                'sub'   => 'need identifying',
            ],
        ];
    }

    /**
     * Games with no metadata, plus how many there are in total.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    private function unmatched(int $total): array
    {
        return [
            'rows'  => $this->games->unidentified(self::UNMATCHED_ROWS)
                ->map(fn(Game $game) => $this->row($game))
                ->all(),
            'total' => $total,
        ];
    }

    /**
     * A game as the dashboard renders it: the shared enriched payload plus the
     * console it belongs to, which that payload deliberately omits.
     *
     * @return array<string, mixed>
     */
    private function row(Game $game): array
    {
        $console = Console::tryFrom((string) $game->console);

        return [
            ...$this->gameData->enrichGame($game),
            'console'        => (string) $game->console,
            'console_name'   => $console?->name ?? (string) $game->console,
            'console_folder' => $console?->folder ?? (string) $game->console,
        ];
    }
}
