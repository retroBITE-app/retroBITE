<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\LabelledOptions;
use App\Models\AppSetting;
use App\Support\Console;
use Illuminate\Support\Collection;

/**
 * The orders the library's consoles can be listed in, picked app-wide in
 * Settings → UI for the sidebar and the Consoles page alike.
 */
enum ConsoleSort: string
{
    use LabelledOptions;

    case Name = 'name';
    case Year = 'year';
    case Games = 'games';

    /**
     * The stored order, or Name when none is stored or the stored key names an
     * order that no longer exists.
     */
    public static function current(): self
    {
        return self::tryFrom((string) AppSetting::get(AppSetting::UI_CONSOLE_SORT)) ?? self::Name;
    }

    /**
     * Human-readable name for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Name => __('A–Z'),
            self::Year => __('Year'),
            self::Games => __('Most games'),
        };
    }

    /**
     * The consoles in this order, the name breaking every tie.
     *
     * Year runs oldest first, with the front-ends that were never released as
     * one machine after every dated console; Games runs most first, counting
     * identified games as the sidebar does.
     *
     * @param  Collection<int, Console>  $consoles
     * @param  Collection<string, int|string>  $gameCounts  identified games by console key
     * @return Collection<int, Console>
     */
    public function apply(Collection $consoles, Collection $gameCounts): Collection
    {
        return $consoles
            ->sort(function (Console $a, Console $b) use ($gameCounts): int {
                $first = match ($this) {
                    self::Name => 0,
                    self::Year => [$a->released === null, $a->released] <=> [$b->released === null, $b->released],
                    self::Games => (int) $gameCounts->get($b->key, 0) <=> (int) $gameCounts->get($a->key, 0),
                };

                return $first !== 0 ? $first : strnatcasecmp($a->name, $b->name);
            })
            ->values();
    }
}
