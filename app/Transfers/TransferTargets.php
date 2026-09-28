<?php

declare(strict_types=1);

namespace App\Transfers;

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
}
