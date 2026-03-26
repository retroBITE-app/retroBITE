<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Inertia\Inertia;
use App\Repositories\GameRepository;
use App\Models\Game;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ConsoleController
{
    private static array $KNOWN_CONSOLES = [];

    public function __construct(private GameRepository $games)
    {
        self::$KNOWN_CONSOLES = array_keys(config('consoles'));
    }

    /**
     * List all consoles with games
     */
    public function index(Request $request, Response $response): Response
    {
        $counts = Game::selectRaw("
                console,
                SUM(CASE WHEN file_path NOT LIKE '%/BIOS/%' THEN 1 ELSE 0 END) as game_count,
                SUM(CASE WHEN file_path LIKE '%/BIOS/%' THEN 1 ELSE 0 END) as bios_count
            ")
            ->groupBy('console')
            ->get()
            ->keyBy('console');

        $consoles = Collection::make(config('consoles'))
            ->map(fn(array $meta, string $key) => [
                'key'       => $key,
                'name'      => $meta['name'],
                'icon'      => $meta['icon'],
                'gameCount' => (int) ($counts->get($key)?->game_count ?? 0),
                'biosCount' => (int) ($counts->get($key)?->bios_count ?? 0),
            ])
            ->values();

        return Inertia::render($response, 'Consoles/Index', [
            'consoles' => $consoles,
        ]);
    }

    /**
     * Show games for a console
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $console = Arr::get($args, 'console');

        if (!in_array($console, self::$KNOWN_CONSOLES, true)) {
            return $response->withStatus(404);
        }

        $extensions = [
            'files' => config("consoles.{$console}.file_extensions", []),
            'bios'  => config("consoles.{$console}.bios_extensions", []),
        ];

        $type       = $request->getQueryParams()['type'] ?? null;
        $filterExts = $extensions[$type] ?? [];

        $games = $this->games->allForConsole($console, $filterExts, $type);

        return Inertia::render($response, 'Consoles/Show', [
            'console'    => $console,
            'meta'       => config("consoles.{$console}"),
            'games'      => $games,
            'extensions' => $extensions,
            'type'       => $type,
        ]);
    }

    /**
     * Scan the filesystem for games and update DB metadata
     */
    public function scan(Request $request, Response $response, array $args): Response
    {
        $console = Arr::get($args, 'console');

        if (!in_array($console, self::$KNOWN_CONSOLES, true)) {
            return $response->withStatus(404);
        }

        $dir          = config('settings.games_path') . '/' . $console;
        $extensions   = config("consoles.{$console}.file_extensions", []);
        $excludeFiles = config("consoles.{$console}.exclude_files", []);

        if (!is_dir($dir)) {
            return $response->withStatus(404);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if (in_array($file->getFilename(), $excludeFiles, true)) {
                continue;
            }

            if (!in_array(strtolower($file->getExtension()), $extensions, true)) {
                continue;
            }

            $this->games->upsert($console, $file->getFilename(), $file->getPathname(), $file->getSize());
        }

        return Inertia::redirect($response, '/consoles/' . $console);
    }
}
