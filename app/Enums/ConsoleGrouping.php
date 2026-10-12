<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\LabelledOptions;
use App\Models\AppSetting;
use App\Support\Console;
use Illuminate\Support\Collection;

/**
 * What the library's consoles can be grouped by, picked app-wide in
 * Settings → UI: headings in the sidebar, sections on the Consoles page.
 */
enum ConsoleGrouping: string
{
    use LabelledOptions;

    case None = 'none';
    case Brand = 'brand';
    case Decade = 'decade';
    case Category = 'category';

    /**
     * The stored grouping, or None when none is stored or the stored key names
     * a grouping that no longer exists.
     */
    public static function current(): self
    {
        return self::tryFrom((string) AppSetting::get(AppSetting::UI_CONSOLE_GROUPING)) ?? self::None;
    }

    /**
     * Human-readable name for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::None => __('None'),
            self::Brand => __('Brand'),
            self::Decade => __('Decade'),
            self::Category => __('Type'),
        };
    }

    /**
     * Already-sorted consoles split into groups, keyed by heading, in the order
     * the groups are listed; the sort is kept inside each.
     *
     * None is one group under an empty heading, so a caller draws one loop
     * either way. No consoles is no groups, so an empty library stays empty.
     *
     * @param  Collection<int, Console>  $sorted
     * @return Collection<string, Collection<int, Console>>
     */
    public function apply(Collection $sorted): Collection
    {
        if ($sorted->isEmpty()) {
            return new Collection;
        }

        if ($this === self::None) {
            return new Collection(['' => $sorted->values()]);
        }

        return $sorted
            ->groupBy(function (Console $console): string {
                return $this->headingFor($console);
            })
            ->sortBy(function (Collection $group): int|string {
                return $this->rankOf($group->first());
            })
            ->map(function (Collection $group): Collection {
                return $group->values();
            });
    }

    /** The heading a console is listed under. */
    private function headingFor(Console $console): string
    {
        return match ($this) {
            self::None => '',
            self::Brand => $console->brand !== '' ? $console->brand : __('Other'),
            // ":decade" is replaced inside ":decades", leaving "1990s".
            self::Decade => $console->released !== null
                ? __(':decades', ['decade' => intdiv($console->released, 10) * 10])
                : __('No year'),
            self::Category => $console->category->label(),
        };
    }

    /**
     * Where a console's group stands among the others: brands A to Z, decades
     * oldest first with the undated last, categories in the enum's order.
     */
    private function rankOf(Console $console): int|string
    {
        return match ($this) {
            self::None => 0,
            self::Brand => mb_strtolower($console->brand !== '' ? $console->brand : '~'),
            self::Decade => $console->released ?? PHP_INT_MAX,
            self::Category => (int) array_search($console->category, ConsoleCategory::cases(), true),
        };
    }
}
