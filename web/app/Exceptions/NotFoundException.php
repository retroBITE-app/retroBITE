<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A referenced console, game or setting does not exist.
 */
final class NotFoundException extends DomainException
{
    /**
     * HTTP status this failure is reported as.
     */
    public function status(): int
    {
        return 404;
    }

    /**
     * Stable code the frontend branches on.
     */
    public function code(): string
    {
        return 'not_found';
    }

    /**
     * A console key that is not in config/consoles.php.
     */
    public static function console(?string $key): self
    {
        return new self('Unknown console' . ($key === null ? '' : ": {$key}"));
    }

    /**
     * A game id with no row behind it.
     */
    public static function game(string $id): self
    {
        return new self("Unknown game: {$id}");
    }

    /**
     * A group that is not declared overridable.
     */
    public static function settingsGroup(string $group): self
    {
        return new self("Unknown settings group: {$group}");
    }

    /**
     * An item that does not exist in the group.
     */
    public static function settingsItem(string $group, string $key): self
    {
        return new self("Unknown item '{$key}' in group '{$group}'");
    }
}
