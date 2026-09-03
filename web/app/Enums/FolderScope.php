<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two non-literal values a console folder filter can take. Anything else is
 * a real subfolder name.
 */
enum FolderScope: string
{
    /** No filter — every game for the console. */
    case All = '';

    /** Only games sitting directly in the console root, not in a subfolder. */
    case Root = 'root';

    /**
     * Human-readable name for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::All  => 'All',
            self::Root => 'Root',
        };
    }
}
