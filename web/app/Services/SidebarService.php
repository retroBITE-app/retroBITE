<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\GameRepository;
use App\Support\Console;

/**
 * The data the sidebar shows on every authenticated page: the installed consoles
 * and how full the library volume is.
 *
 * Shared through Inertia rather than passed per page, since the sidebar lives in
 * the layout. AuthMiddleware shares it as a closure so these queries only run
 * when a page actually renders — not on the JSON endpoints.
 */
class SidebarService
{
    public function __construct(
        private GameRepository $games,
        private HostSummaryService $host,
    ) {}

    /**
     * @return array{consoles: array<int, array<string, mixed>>, storage: array{used: string, total: ?string, percent: ?int}}
     */
    public function payload(): array
    {
        return [
            'consoles' => $this->installedConsoles(),
            'storage'  => $this->host->storageMeter(),
        ];
    }

    /**
     * Installed consoles with their game counts, in config order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function installedConsoles(): array
    {
        $counts = $this->games->consoleCounts();

        return Console::allInstalled()
            ->map(fn(Console $console) => $console->toCardArray($counts->get($console->key)))
            ->all();
    }
}
