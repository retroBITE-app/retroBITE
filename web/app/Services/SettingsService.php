<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\SettingRepository;
use App\Support\Registry;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Reads and writes the runtime config overrides behind the settings page.
 */
class SettingsService
{
    public function __construct(
        private SettingRepository $settings,
    ) {}

    /**
     * Every overridable group, with its schema, current values, and which of them
     * are currently overridden.
     *
     * @return array<int, array{slug: string, label: string, schema: array, items: array, overrides: string[]}>
     */
    public function groups(): array
    {
        $overrides = $this->settings->asMap();

        return Collection::make(Registry::groups())
            ->map(fn(string $slug) => [
                'slug'      => $slug,
                'label'     => Registry::labelFor($slug),
                'schema'    => Registry::schemaFor($slug),
                'items'     => $this->itemsFor($slug),
                'overrides' => array_keys($overrides[$slug] ?? []),
            ])
            ->values()
            ->all();
    }

    /**
     * Validate and store an override for one existing item, returning what was
     * stored.
     *
     * @throws NotFoundException|ValidationException
     */
    public function save(string $group, string $key, mixed $body): array
    {
        $this->assertTargetExists($group, $key, mustExist: true);

        if (!is_array($body)) {
            throw ValidationException::because('Body must be a JSON object');
        }

        $normalized = Registry::normalize($group, $body);
        $errors     = Registry::validate($group, $normalized);

        if ($errors !== []) {
            throw ValidationException::fields($errors);
        }

        $this->settings->upsert($group, $key, $normalized);

        return $normalized;
    }

    /**
     * Drop an override so config() falls back to the file default. The item need
     * not still exist — orphaned rows must stay deletable.
     *
     * @throws NotFoundException|ValidationException
     */
    public function reset(string $group, string $key): int
    {
        $this->assertTargetExists($group, $key, mustExist: false);

        return $this->settings->delete($group, $key);
    }

    /**
     * Reject a group or key we will not write to.
     *
     * @throws NotFoundException|ValidationException
     */
    private function assertTargetExists(string $group, string $key, bool $mustExist): void
    {
        if (!Registry::has($group)) {
            throw NotFoundException::settingsGroup($group);
        }

        if (!Registry::isValidKey($key)) {
            throw ValidationException::because('Invalid key');
        }

        if ($mustExist && !Registry::hasItem($group, $key)) {
            throw NotFoundException::settingsItem($group, $key);
        }
    }

    /**
     * Merged items for a group, limited to schema fields so internal keys never
     * reach the browser.
     *
     * @return array<string, array<string, mixed>>
     */
    private function itemsFor(string $group): array
    {
        $schema = array_keys(Registry::schemaFor($group));

        return Collection::make((array) config($group, []))
            ->filter(fn(mixed $value) => is_array($value))
            ->map(fn(array $value) => Arr::only($value, $schema))
            ->all();
    }
}
