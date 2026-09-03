<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Arr;

/**
 * Field types an overridable config entry may declare in config/overridable.php.
 * Mirrored by the union in resources/js/Types/api.ts — keep the two in step.
 */
enum SettingFieldType: string
{
    case Text     = 'text';
    case Number   = 'number';
    case TextList = 'text[]';
    case Url      = 'url';

    /**
     * Human-readable name for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Text     => 'Text',
            self::Number   => 'Number',
            self::TextList => 'List of text',
            self::Url      => 'URL',
        };
    }

    /**
     * Read a type name from a schema entry, defaulting to Text for anything unknown.
     */
    public static function fromSchema(mixed $value): self
    {
        return self::tryFrom((string) $value) ?? self::Text;
    }

    /**
     * Coerce a raw submitted value to this type.
     */
    public function coerce(mixed $value): mixed
    {
        return match ($this) {
            self::Number         => $value === null || $value === '' ? null : (int) $value,
            self::TextList       => $this->coerceList($value),
            self::Text, self::Url => $value === null ? null : (string) $value,
        };
    }

    /**
     * Why this value is unacceptable for this type, or null when it is fine.
     */
    public function validate(mixed $value): ?string
    {
        return match (true) {
            $this === self::Number && $value !== null && $value !== '' && !is_numeric($value) => 'Must be a number',
            $this === self::TextList && $value !== null && !is_array($value)                  => 'Must be a list',
            $this === self::Url && !$this->isRenderableUrl($value)                            => 'Must be an http(s) or root-relative URL',
            default                                                                            => null,
        };
    }

    /**
     * Is this safe to put in an `src` attribute? These values are user-editable and
     * rendered straight into <img>, so `javascript:` and `data:` are refused.
     */
    private function isRenderableUrl(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * Is this value empty for the purposes of a required check?
     */
    public function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && $value === []);
    }

    /**
     * Cast every entry to a non-empty string, discarding blanks.
     *
     * @return string[]
     */
    private function coerceList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(Arr::where(
            Arr::map($value, fn(mixed $item): string => (string) $item),
            fn(string $item): bool => $item !== '',
        ));
    }
}
