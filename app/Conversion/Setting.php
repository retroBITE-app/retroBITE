<?php

declare(strict_types=1);

namespace App\Conversion;

/**
 * One tunable of a conversion's tool — maxcso's block size, chdman's hunk
 * size — shown under Advanced options.
 *
 * A closed list of choices rather than free input: whatever the page sends
 * ends up on a command line, so only a value the converter named is ever
 * passed, and anything else is the default.
 */
final class Setting
{
    /**
     * Value => label. Numeric values arrive as integer keys, which is how PHP
     * keeps "2048"; they are compared as strings all the same.
     *
     * @var array<int|string, string>
     */
    public readonly array $choices;

    /**
     * @param  array<int|string, mixed>  $choices  value => label
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly string $default,
        array $choices,
    ) {
        $this->choices = array_map(function (mixed $label): string {
            return is_string($label) ? $label : '';
        }, $choices);
    }

    /** The value asked for, when it is one of the choices; the default otherwise. */
    public function resolve(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return array_key_exists($value, $this->choices) ? $value : $this->default;
    }
}
