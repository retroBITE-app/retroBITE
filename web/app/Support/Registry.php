<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SettingFieldType;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

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
     * Validate an item payload against a group's schema.
     *
     * @return array<string, string> Field => message; empty means valid.
     */
    public static function validate(string $group, array $payload): array
    {
        $errors = [];

        foreach (self::schemaFor($group) as $field => $meta) {
            $type  = SettingFieldType::fromSchema(Arr::get($meta, 'type'));
            $value = Arr::get($payload, $field);

            if ((bool) Arr::get($meta, 'required', false) && $type->isEmpty($value)) {
                $errors[$field] = 'Required';
                continue;
            }

            if ($message = $type->validate($value)) {
                $errors[$field] = $message;
            }
        }

        return $errors;
    }

    /**
     * Normalize an item payload: drop unknown fields, coerce types on known fields.
     */
    public static function normalize(string $group, array $payload): array
    {
        return Collection::make(self::schemaFor($group))
            ->filter(fn(array $meta, string $field) => array_key_exists($field, $payload))
            ->map(fn(array $meta, string $field) => SettingFieldType::fromSchema(Arr::get($meta, 'type'))
                ->coerce($payload[$field]))
            ->all();
    }
}
