<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The `status` values the JSON API reports back. Part of the frontend contract.
 */
enum ResponseStatus: string
{
    case Ok       = 'ok';
    case Received = 'received';
    case Complete = 'complete';

    /**
     * Human-readable name for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ok       => 'OK',
            self::Received => 'Received',
            self::Complete => 'Complete',
        };
    }
}
