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

    /**
     * Human-readable group name for the settings UI, falling back to the slug.
     */
    public static function labelFor(string $group): string
    {
        return (string) config("overridable.{$group}.label", $group);
    }

    /**
     * Is this group declared overridable?
     */
    public static function has(string $group): bool
    {
        return is_array(config("overridable.{$group}"));
    }

    /**
     * Does the group already contain this item? Overrides may only edit existing
     * entries — an unknown key would invent one missing every unexposed field.
     */
    public static function hasItem(string $group, string $key): bool
    {
        return is_array(config("{$group}.{$key}"));
    }

    /**
     * Is this a syntactically valid item key? Item keys become config path segments,
     * so they are restricted to a conservative slug alphabet.
     */
    public static function isValidKey(string $key): bool
    {
        return preg_match('/^[a-zA-Z0-9_\-]+$/', $key) === 1;
    }

    /**
     * Coerce a raw incoming value to the schema-declared type. Returns the normalized value.
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
     * Normalize an item payload: drop unknown fields, coerce types on known fields.
     */
    public static function normalize(string $group, array $payload): array
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
