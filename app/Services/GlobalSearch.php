<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MediaKind;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Resources\ConsoleResource;
use App\Resources\DocResource;
use App\Support\Console;
use App\Support\Navigation;
use Illuminate\Support\Str;

/**
 * What the Ctrl+K box finds: games, then docs, consoles, pages and settings.
 *
 * Games always lead, being what is searched for nearly every time, and are the
 * only group that asks the database anything: one query, a handful of rows,
 * the columns a row shows and the covers of those rows only. The rest are
 * lists already in memory — the library's consoles are read once a request
 * anyway — filtered as they are. Docs come out of DocLibrary's own index,
 * which reads a file again only when it has changed.
 */
final class GlobalSearch
{
    public function __construct(private readonly DocLibrary $docLibrary) {}

    /** Games shown; one more page of them is the games list's job. */
    public const GAME_LIMIT = 6;

    public const CONSOLE_LIMIT = 5;

    public const DOC_LIMIT = 5;

    /** Shorter terms find too many games to be worth a query. */
    public const GAME_MIN_LENGTH = 2;

    /**
     * The groups that found something, in the order they are shown.
     *
     * An empty term is the quick-jump list: every page and settings tab, and
     * no game query at all.
     *
     * @return list<array{key: string, label: string, items: list<array{label: string, detail: ?string, url: string, icon: ?string, image: ?string}>}>
     */
    public function results(string $term): array
    {
        $term = trim($term);
        $needle = mb_strtolower($term);

        $groups = [
            ['key' => 'games', 'label' => (string) __('Games'), 'items' => $this->games($term)],
            ['key' => 'docs', 'label' => (string) __('Documents'), 'items' => $this->docs($term)],
            ['key' => 'consoles', 'label' => (string) __('Consoles'), 'items' => $term === '' ? [] : $this->consoles($needle)],
            ['key' => 'pages', 'label' => (string) __('Pages'), 'items' => $this->places(Navigation::pages(), $needle, null)],
            ['key' => 'settings', 'label' => (string) __('Settings'), 'items' => $this->places(Navigation::settings(), $needle, (string) __('Settings'))],
        ];

        return array_values(array_filter($groups, function (array $group): bool {
            return $group['items'] !== [];
        }));
    }

    /**
     * The best few titles for the term: those starting with it first, then
     * those holding it anywhere, alphabetical within each. A full row of them
     * ends with a link to the games list, which shows the rest.
     *
     * @return list<array{label: string, detail: ?string, url: string, icon: ?string, image: ?string}>
     */
    private function games(string $term): array
    {
        if (mb_strlen($term) < self::GAME_MIN_LENGTH) {
            return [];
        }

        $games = Game::query()
            ->search($term)
            ->select(['id', 'console', 'slug', 'title'])
            ->orderByRaw('games.title like ? desc', [Game::escapeLike($term).'%'])
            ->orderBy('title')
            ->limit(self::GAME_LIMIT)
            ->with(['media' => function ($query): void {
                $query->ofKind(MediaKind::Cover);
            }])
            ->get();

        $items = array_values($games->map(function (Game $game): array {
            $console = $game->console();

            return [
                'label' => $game->title,
                'detail' => $console?->name,
                'url' => route('games.show', $game->routeParameters()),
                'icon' => null,
                'image' => $game->listThumbnail(),
            ];
        })->all());

        if ($games->count() === self::GAME_LIMIT) {
            $items[] = [
                'label' => (string) __('See all games matching “:term”', ['term' => $term]),
                'detail' => null,
                'url' => route('games.index', ['q' => $term]),
                'icon' => 'arrow-right',
                'image' => null,
            ];
        }

        return $items;
    }

    /**
     * The docs the term finds in their title, tags, category or text, newest
     * first, as the Docs page lists them.
     *
     * @return list<array{label: string, detail: ?string, url: string, icon: ?string, image: ?string}>
     */
    private function docs(string $term): array
    {
        if (mb_strlen($term) < self::GAME_MIN_LENGTH) {
            return [];
        }

        return array_values($this->docLibrary->search($term)
            ->take(self::DOC_LIMIT)
            ->map(function (DocResource $doc): array {
                $console = $doc->console !== '' ? ConsoleResource::make($doc->console)?->name : null;

                return [
                    'label' => $doc->title,
                    'detail' => $console ?? ($doc->category !== '' ? Str::headline($doc->category) : (string) __('Doc')),
                    'url' => route('docs.index', ['doc' => $doc->path]),
                    'icon' => 'document-text',
                    'image' => null,
                ];
            })
            ->all());
    }

    /**
     * The library's consoles the term finds, by name, brand or key.
     *
     * @return list<array{label: string, detail: ?string, url: string, icon: ?string, image: ?string}>
     */
    private function consoles(string $needle): array
    {
        return array_values(ConsoleSourceFolder::consoles()
            ->filter(function (Console $console) use ($needle): bool {
                return $console->matches($needle);
            })
            ->take(self::CONSOLE_LIMIT)
            ->map(function (Console $console): array {
                return [
                    'label' => $console->name,
                    'detail' => $console->brand,
                    'url' => route('consoles.games', ['console' => $console->key]),
                    'icon' => null,
                    'image' => $console->icon,
                ];
            })
            ->all());
    }

    /**
     * The places a term finds, by label or keyword; all of them for none.
     *
     * @param  list<array{label: string, route: string, icon: string, keywords: string}>  $places
     * @return list<array{label: string, detail: ?string, url: string, icon: ?string, image: ?string}>
     */
    private function places(array $places, string $needle, ?string $detail): array
    {
        return array_values(collect($places)
            ->filter(function (array $place) use ($needle): bool {
                return $needle === '' || str_contains(mb_strtolower(__($place['label']).' '.$place['label'].' '.$place['keywords']), $needle);
            })
            ->map(function (array $place) use ($detail): array {
                return [
                    'label' => (string) __($place['label']),
                    'detail' => $detail,
                    'url' => route($place['route']),
                    'icon' => $place['icon'],
                    'image' => null,
                ];
            })
            ->all());
    }
}
