<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Which of the provider's media types are fetched, and what may be chosen.
 *
 * The catalogue is config because the provider invents the types; the choice is
 * a setting because a person makes it. Splitting them that way is what let the
 * media_type_preferences table go: the database only has to remember the
 * answer, not the question.
 */
final class MediaTypes
{
    /**
     * The catalogue as the settings screen draws it.
     *
     * Anything switched on that config no longer lists gets a group of its own,
     * so trimming the catalogue cannot quietly discard somebody's choice.
     *
     * @return array<int, array{label: string, types: array<int, string>}>
     */
    public static function grouped(): array
    {
        /** @var array<int, array{label: string, types: array<int, string>}> $groups */
        $groups = (array) config('media_types.groups', []);

        $extra = array_values(array_diff(self::enabled(), self::catalogue()));

        return $extra === []
            ? $groups
            : [...$groups, ['label' => 'Other', 'types' => $extra]];
    }

    /**
     * Every type the screen offers, flat, in the order the groups list them.
     *
     * @return array<int, string>
     */
    public static function offered(): array
    {
        return Collection::make(self::grouped())
            ->flatMap(fn (array $group): array => Arr::get($group, 'types', []))
            ->values()
            ->all();
    }

    /**
     * What a type is, in words, or null for one the catalogue does not describe.
     *
     * @return array{name: string, description: string}|null
     */
    public static function describe(string $type): ?array
    {
        $described = config('media_types.types.'.$type);

        return is_array($described) && isset($described['name'], $described['description'])
            ? ['name' => (string) $described['name'], 'description' => (string) $described['description']]
            : null;
    }

    /**
     * The types to fetch, as a plain list.
     *
     * Nothing stored means nobody has been to the settings screen, which is not
     * the same as switching everything off: a fresh install fetches the shipped
     * selection, an emptied list fetches nothing.
     *
     * @return array<int, string>
     */
    public static function enabled(): array
    {
        $stored = AppSetting::get(AppSetting::MEDIA_TYPES);

        if (! is_array($stored)) {
            return self::defaults();
        }

        return array_values(array_map(strval(...), $stored));
    }

    /**
     * Keep a choice made in the settings screen.
     *
     * @param  array<int, string>  $types
     */
    public static function remember(array $types): void
    {
        AppSetting::put(AppSetting::MEDIA_TYPES, array_values($types));
    }

    /**
     * Every type config lists, ignoring which are switched on.
     *
     * @return array<int, string>
     */
    private static function catalogue(): array
    {
        return Collection::make((array) config('media_types.groups', []))
            ->flatMap(fn (array $group): array => Arr::get($group, 'types', []))
            ->values()
            ->all();
    }

    /**
     * The selection a host starts with.
     *
     * @return array<int, string>
     */
    private static function defaults(): array
    {
        return array_values((array) config('media_types.default_enabled', []));
    }
}
