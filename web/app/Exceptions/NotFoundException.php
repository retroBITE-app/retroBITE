<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A referenced console, game or setting does not exist.
 */
final class NotFoundException extends DomainException
{
    public function status(): int
    {
        return 404;
    }

    public function code(): string
    {
        return 'not_found';
    }

    public static function console(?string $key): self
    {
        return new self('Unknown console' . ($key === null ? '' : ": {$key}"));
    }

    public static function game(string $id): self
    {
        return new self("Unknown game: {$id}");
    }

    public static function settingsGroup(string $group): self
    {
        return new self("Unknown settings group: {$group}");
    }

    public static function settingsItem(string $group, string $key): self
    {
        return new self("Unknown item '{$key}' in group '{$group}'");
    }
}
