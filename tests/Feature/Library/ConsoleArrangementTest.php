<?php

use App\Enums\ConsoleCategory;
use App\Enums\ConsoleGrouping;
use App\Enums\ConsoleSort;
use App\Models\AppSetting;
use App\Models\ConsoleSourceFolder;
use App\Support\Console;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/** Five consoles that cover every branch: two decades, an undated one, three brands, three categories. */
function arrangedFixture(): Collection
{
    return collect(['snes', 'psx', 'mame', 'nes', 'gb'])->map(function (string $key): Console {
        return new Console($key);
    });
}

/** @return array<int|string, list<string>> keys under each heading */
function headingKeys(Collection $groups): array
{
    return $groups->map(function (Collection $consoles): array {
        return $consoles->pluck('key')->all();
    })->all();
}

it('sorts by name, ties and all', function () {
    expect(ConsoleSort::Name->apply(arrangedFixture(), collect())->pluck('key')->all())
        ->toBe(['gb', 'mame', 'nes', 'psx', 'snes']);
});

it('sorts oldest first, with the undated front-ends last', function () {
    expect(ConsoleSort::Year->apply(arrangedFixture(), collect())->pluck('key')->all())
        ->toBe(['nes', 'gb', 'snes', 'psx', 'mame']);
});

it('sorts the most games first, by name where the count is the same', function () {
    $counts = collect(['snes' => 5, 'gb' => '5', 'psx' => 9]);

    expect(ConsoleSort::Games->apply(arrangedFixture(), $counts)->pluck('key')->all())
        ->toBe(['psx', 'gb', 'snes', 'mame', 'nes']);
});

it('leaves an ungrouped list as one group without a heading', function () {
    $sorted = ConsoleSort::Name->apply(arrangedFixture(), collect());

    expect(headingKeys(ConsoleGrouping::None->apply($sorted)))->toBe(['' => ['gb', 'mame', 'nes', 'psx', 'snes']])
        ->and(ConsoleGrouping::None->apply(collect()))->toBeEmpty();
});

it('groups by brand A to Z, keeping the sort inside each', function () {
    $sorted = ConsoleSort::Year->apply(arrangedFixture(), collect());

    expect(headingKeys(ConsoleGrouping::Brand->apply($sorted)))->toBe([
        'Nintendo' => ['nes', 'gb', 'snes'],
        'Sony' => ['psx'],
        'Various' => ['mame'],
    ]);
});

it('groups by decade oldest first, the undated last', function () {
    $sorted = ConsoleSort::Name->apply(arrangedFixture(), collect());

    expect(headingKeys(ConsoleGrouping::Decade->apply($sorted)))->toBe([
        '1980s' => ['gb', 'nes'],
        '1990s' => ['psx', 'snes'],
        'No year' => ['mame'],
    ]);
});

it('groups by type in the order the types are declared', function () {
    $sorted = ConsoleSort::Name->apply(arrangedFixture(), collect());

    expect(headingKeys(ConsoleGrouping::Category->apply($sorted)))->toBe([
        'Home console' => ['nes', 'psx', 'snes'],
        'Handheld' => ['gb'],
        'Arcade' => ['mame'],
    ]);
});

it('arranges the library as Settings → UI says', function () {
    foreach (['snes', 'gb', 'psx'] as $key) {
        ConsoleSourceFolder::add(new Console($key));
    }

    expect(headingKeys(ConsoleSourceFolder::arranged(collect())))->toBe(['' => ['gb', 'psx', 'snes']]);

    AppSetting::put(AppSetting::UI_CONSOLE_SORT, 'year');
    AppSetting::put(AppSetting::UI_CONSOLE_GROUPING, 'category');

    expect(headingKeys(ConsoleSourceFolder::arranged(collect())))->toBe([
        'Home console' => ['snes', 'psx'],
        'Handheld' => ['gb'],
    ]);
});

it('gives every shipped console a type it knows', function () {
    $unknown = collect((array) config('consoles'))
        ->reject(function (array $console): bool {
            return ConsoleCategory::tryFrom((string) Arr::get($console, 'category')) !== null;
        })
        ->keys()
        ->all();

    expect($unknown)->toBe([]);
});

it('reads a console with no type as a home console', function () {
    config()->set('consoles.snes.category', null);

    expect((new Console('snes'))->category)->toBe(ConsoleCategory::Home);
});
