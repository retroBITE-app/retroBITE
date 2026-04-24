<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * Thin façade over config('overridable') — the registry of user-editable config groups.
 */
final class Registry
{
    /** @return string[] Overridable group slugs (e.g. ['consoles', 'regions']). */
    public static function groups(): array
    {
        return array_keys((array) config('overridable', []));
    }

    /**
     * Per-field schema for a group, keyed by field name.
     *
     * @return array<string, array{type: string, label?: string, required?: bool}>
     */
    public static function schemaFor(string $group): array
    {
        return (array) config("overridable.{$group}.schema", []);
    }

    public static function labelFor(string $group): string
    {
        return (string) config("overridable.{$group}.label", $group);
    }

    public static function has(string $group): bool
    {
        return is_array(config("overridable.{$group}"));
    }

    /**
     * Coerce a raw incoming value to the schema-declared type. Returns the normalised value.
     */
    public static function coerce(string $type, mixed $value): mixed
    {
        return match ($type) {
            'number' => $value === null || $value === '' ? null : (int) $value,
            'text[]' => self::coerceList($value),
            default  => $value === null ? null : (string) $value,
        };
    }

    /**
     * Validate an item payload against a group's schema. Returns ['field' => 'message', ...].
     * Empty array means valid.
     *
     * @return array<string, string>
     */
    public static function validate(string $group, array $payload): array
    {
        $errors = [];

        foreach (self::schemaFor($group) as $field => $meta) {
            $type     = (string) Arr::get($meta, 'type', 'text');
            $required = (bool)   Arr::get($meta, 'required', false);
            $value    = Arr::get($payload, $field);

            if ($required && ($value === null || $value === '' || (is_array($value) && $value === []))) {
                $errors[$field] = 'Required';
                continue;
            }

            if ($type === 'number' && $value !== null && $value !== '' && !is_numeric($value)) {
                $errors[$field] = 'Must be a number';
            }

            if ($type === 'text[]' && $value !== null && !is_array($value)) {
                $errors[$field] = 'Must be a list';
            }
        }

        return $errors;
    }

    /**
     * Normalise an item payload: drop unknown fields, coerce types on known fields.
     */
    public static function normalise(string $group, array $payload): array
    {
        $out = [];
        foreach (self::schemaFor($group) as $field => $meta) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $out[$field] = self::coerce((string) Arr::get($meta, 'type', 'text'), $payload[$field]);
        }

        return $out;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function coerceList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn($v) => (string) $v, $value),
            fn(string $v) => $v !== '',
        ));
    }
}
