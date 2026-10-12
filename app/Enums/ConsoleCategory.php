<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\LabelledOptions;

/**
 * What kind of machine a console is, for grouping the console lists.
 *
 * Carried by each config/consoles/ file as `category`, and overridable per
 * console. The case order is the order the groups are listed in.
 */
enum ConsoleCategory: string
{
    use LabelledOptions;

    case Home = 'home';
    case Handheld = 'handheld';
    case Computer = 'computer';
    case Arcade = 'arcade';

    /**
     * The category a console file names, or Home for one that names none or
     * one that does not exist: most of what the app knows is a home console.
     */
    public static function fromConfig(mixed $value): self
    {
        return self::tryFrom((string) $value) ?? self::Home;
    }

    /**
     * Human-readable name for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Home => __('Home console'),
            self::Handheld => __('Handheld'),
            self::Computer => __('Computer'),
            self::Arcade => __('Arcade'),
        };
    }
}
