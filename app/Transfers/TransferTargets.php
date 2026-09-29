<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Models\ConsoleSourceFolder;
use App\Support\Console;

/** The configured transfer targets, by key. */
final class TransferTargets
{
    public static function find(string $key): ?TransferTarget
    {
        $class = config('transfer.targets.'.$key);

        return is_string($class) && is_a($class, TransferTarget::class, true) ? app($class) : null;
    }

    /** @return list<TransferTarget> */
    public static function all(): array
    {
        return array_values(array_filter(array_map(
            fn (string $key): ?TransferTarget => self::find($key),
            array_keys((array) config('transfer.targets')),
        )));
    }

    /**
     * The targets that play a console's games, in config order.
     *
     * @return list<TransferTarget>
     */
    public static function for(Console $console): array
    {
        return array_values(array_filter(self::all(), function (TransferTarget $target) use ($console): bool {
            return $target->supports($console);
        }));
    }

    /**
     * The target to offer first for a console: the one its library is laid
     * out for, where a target shares the layout's key — a PS2 library
     * arranged for OPL is most likely going to OPL — else the first there is.
     */
    public static function recommendedFor(Console $console): ?TransferTarget
    {
        $targets = collect(self::for($console));
        $layout = ConsoleSourceFolder::layoutKeyFor($console);

        return $targets->first(function (TransferTarget $target) use ($layout): bool {
            return $target->key() === $layout;
        }) ?? $targets->first();
    }
}
