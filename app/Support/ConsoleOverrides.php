<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

final class ConsoleOverrides
{
    /**
     * http(s) or a root-relative path, so an icon cannot be a javascript: URI.
     *
     * Spaces are allowed: every shipped icon path carries the console's full
     * name, e.g. /images/consoles/Nintendo - Super Nintendo Entertainment System.png.
     */
    private const URL_PATTERN = '/^(https?:\/\/|\/)[^<>"\x00-\x1f]*$/';

    /**
     * config('consoles') as the files declare it, before anything was merged.
     *
     * Taken once so that applying twice cannot compound, and so that dropping an
     * override restores the shipped value rather than leaving the last merge behind.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $pristine = null;

    /**
     * Console keys the last apply() wrote to.
     *
     * Only these are put back, so a console nobody has overridden is never written
     * to the config repository at all.
     *
     * @var string[]
     */
    private static array $applied = [];

    /**
     * The overridable fields, keyed by config key.
     *
     * @return array<string, array{type: string, label: string, description?: string, required?: bool}>
     */
    public static function schema(): array
    {
        return (array) config('console_overrides', []);
    }

    /**
     * @return string[]
     */
    public static function fields(): array
    {
        return array_keys(self::schema());
    }

    /**
     * Every stored override, narrowed to fields the schema exposes and to consoles
     * that still exist — a console dropped from config must not resurrect itself.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $fields = self::fields();

        return Collection::make(self::stored())
            ->filter(fn (mixed $override, string $key): bool => is_array($override)
                && is_array(Arr::get(self::pristine(), $key)))
            ->map(fn (array $override): array => Arr::only($override, $fields))
            ->reject(fn (array $override): bool => $override === [])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function for(string $key): array
    {
        return (array) Arr::get(self::all(), $key, []);
    }

    public static function has(string $key): bool
    {
        return self::for($key) !== [];
    }

    /**
     * Merge the overrides into the config repository.
     *
     * Per console entry rather than per group: a stored override holds only the
     * schema fields, so replacing a whole entry would drop `folder` and `order`.
     */
    public static function apply(): void
    {
        $pristine = self::pristine();
        $overrides = self::all();

        foreach (self::$applied as $key) {
            config()->set("consoles.{$key}", Arr::get($pristine, $key));
        }

        self::$applied = [];

        foreach ($overrides as $key => $override) {
            $meta = Arr::get($pristine, $key);

            if (! is_array($meta)) {
                continue;
            }

            config()->set("consoles.{$key}", array_replace($meta, $override));
            self::$applied[] = $key;
        }
    }

    /**
     * Keep what a person changed about one console, and put it in force.
     *
     * @param  array<string, mixed>  $values  raw form input, keyed by config key
     */
    public static function remember(string $key, array $values): void
    {
        $defaults = self::defaults($key);

        // An override edits a console that exists. It never invents one.
        if (! is_array(Arr::get(self::pristine(), $key))) {
            return;
        }

        $override = Collection::make(self::schema())
            ->mapWithKeys(fn (array $field, string $name): array => [
                $name => self::normalize($name, Arr::get($values, $name)),
            ])
            ->reject(fn (mixed $value, string $name): bool => $value === null
                || $value === []
                || $value === Arr::get($defaults, $name))
            ->all();

        $stored = self::stored();

        if ($override === []) {
            Arr::forget($stored, $key);
        } else {
            Arr::set($stored, $key, $override);
        }

        self::store($stored);
    }

    /**
     * Put one console back to what its config file says.
     */
    public static function forget(string $key): void
    {
        $stored = self::stored();

        Arr::forget($stored, $key);

        self::store($stored);
    }

    /**
     * Put every console back to what its config file says.
     *
     * The row is emptied rather than deleted, so `get()` still answers with an
     * array and nothing has to tell "never set" from "reset".
     */
    public static function forgetAll(): void
    {
        self::store([]);
    }

    /**
     * What the console file says, before any override.
     *
     * @return array<string, mixed>
     */
    public static function defaults(string $key): array
    {
        return Arr::only((array) Arr::get(self::pristine(), $key, []), self::fields());
    }

    /**
     * What is in force right now, override included.
     *
     * @return array<string, mixed>
     */
    public static function effective(string $key): array
    {
        return Arr::only((array) config("consoles.{$key}", []), self::fields());
    }

    /**
     * The values in force, as the form's text inputs hold them.
     *
     * @return array<string, string>
     */
    public static function toForm(string $key): array
    {
        return self::asFormValues(self::effective($key));
    }

    /**
     * The shipped values, for the placeholder that says what emptying a field means.
     *
     * @return array<string, string>
     */
    public static function toPlaceholders(string $key): array
    {
        return self::asFormValues(self::defaults($key));
    }

    /**
     * Validation for the settings form, derived from the schema so a new field is
     * declared once rather than three times.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return Collection::make(self::schema())
            ->mapWithKeys(function (array $field, string $name): array {
                $rules = match (Arr::get($field, 'type')) {
                    'number' => ['nullable', 'integer', 'min:0', 'max:999999'],
                    'url' => ['nullable', 'string', 'max:255', 'regex:'.self::URL_PATTERN],
                    'region[]' => ['nullable', 'string', 'max:255', 'regex:'.self::regionPattern()],
                    default => ['nullable', 'string', 'max:255'],
                };

                if (Arr::get($field, 'required', false) === true) {
                    $rules[0] = 'required';
                }

                return ["fields.{$name}" => $rules];
            })
            ->all();
    }

    /**
     * Field labels, so a message names "Game extensions" rather than "fields.file_extensions".
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return Collection::make(self::schema())
            ->mapWithKeys(fn (array $field, string $name): array => [
                "fields.{$name}" => mb_strtolower((string) Arr::get($field, 'label', $name)),
            ])
            ->all();
    }

    /**
     * Cast one raw form value to the shape its declared type stores.
     */
    public static function normalize(string $field, mixed $value): mixed
    {
        return match (Arr::get(self::schema(), "{$field}.type")) {
            'ext[]', 'region[]' => self::asList($value, lowercase: true),
            'text[]' => self::asList($value),
            'number' => self::asInt($value),
            default => self::asText($value),
        };
    }

    /** A comma-separated list of region codes the app knows, and nothing else. */
    private static function regionPattern(): string
    {
        $codes = implode('|', array_map(fn (string $code): string => preg_quote($code, '/'), array_keys(MediaRegions::labels())));

        return '/^\s*(?:'.$codes.')(?:\s*,\s*(?:'.$codes.'))*\s*$/i';
    }

    /**
     * Read the stored map, tolerating a table that is not there yet.
     *
     * The providers boot before migrate has run on a fresh install, and a first
     * boot that 500s because of a settings table is worse than one that serves
     * the shipped config.
     *
     * @return array<string, mixed>
     */
    private static function stored(): array
    {
        try {
            $stored = AppSetting::get(AppSetting::CONSOLE_OVERRIDES);
        } catch (Throwable $e) {
            logger()->warning('Could not read console overrides; using the shipped config', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        return is_array($stored) ? $stored : [];
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private static function store(array $stored): void
    {
        AppSetting::put(AppSetting::CONSOLE_OVERRIDES, $stored);

        // Written through rather than left for the next request: the screen that
        // saved reads the console back to redraw its own card.
        self::apply();
    }

    /**
     * @return array<string, mixed>
     */
    private static function pristine(): array
    {
        return self::$pristine ??= (array) config('consoles', []);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private static function asFormValues(array $values): array
    {
        return Collection::make(self::schema())
            ->mapWithKeys(function (array $field, string $name) use ($values): array {
                $value = Arr::get($values, $name);

                return [$name => is_array($value)
                    ? implode(', ', $value)
                    : (string) ($value ?? '')];
            })
            ->all();
    }

    /**
     * @return string[]
     */
    private static function asList(mixed $value, bool $lowercase = false): array
    {
        $items = is_array($value)
            ? $value
            : Str::of((string) $value)->explode(',')->all();

        return Collection::make($items)
            ->map(fn (mixed $item): string => trim((string) $item))
            ->map(fn (string $item): string => $lowercase ? mb_strtolower($item) : $item)
            ->reject(fn (string $item): bool => $item === '')
            ->unique()
            ->values()
            ->all();
    }

    private static function asInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function asText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    /**
     * Forget the snapshot. Tests change config/consoles between cases.
     */
    public static function flush(): void
    {
        self::$pristine = null;
        self::$applied = [];
    }
}
