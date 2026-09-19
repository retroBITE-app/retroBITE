<?php

declare(strict_types=1);

namespace App\Support\RetroAchievements;

use App\Models\Game;
use App\Support\Console;
use Illuminate\Support\Collection;

/**
 * Which RetroAchievements consoles the library actually has games for.
 *
 * The hash index for one console is megabytes, so only the ones somebody owns
 * games for are ever downloaded. Several retroBite consoles can share one
 * RetroAchievements id — mame, fbneo, naomi and three more are all Arcade —
 * so the mapping is one id to many keys, not a pair.
 *
 * @phpstan-type ConsoleKeys array<int, string>
 */
final class LibraryConsoles
{
    /**
     * RetroAchievements console id to the console keys that map onto it.
     *
     * @return Collection<int, array<int, string>>
     */
    public static function mapped(): Collection
    {
        return Game::query()
            ->distinct()
            ->orderBy('console')
            ->pluck('console')
            ->map(fn (string $key) => Console::tryFrom($key))
            ->filter(fn (?Console $console) => $console?->retroachievementsId !== null)
            ->groupBy(fn (Console $console) => (int) $console->retroachievementsId)
            ->map(fn (Collection $consoles) => $consoles->map(fn (Console $c) => $c->key)->values()->all());
    }

    /**
     * The console keys mapping onto one RetroAchievements console id.
     *
     * Read from config rather than from the library, so a rematch still covers
     * a console whose last game was deleted between the sync and the rematch.
     *
     * @return array<int, string>
     */
    public static function keysFor(int $raConsoleId): array
    {
        return Console::all()
            ->filter(fn (Console $console) => $console->retroachievementsId === $raConsoleId)
            ->map(fn (Console $console) => $console->key)
            ->values()
            ->all();
    }
}
