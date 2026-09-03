<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Setting;
use App\Support\SettingsOverrides;
use Illuminate\Database\Eloquent\Collection;

class SettingRepository
{
    /**
     * Every stored override row.
     */
    public function all(): Collection
    {
        return Setting::all();
    }

    /**
     * Insert or update a single override. `$value` is JSON-encoded before storage.
     */
    public function upsert(string $group, string $key, mixed $value): Setting
    {
        Setting::upsert(
            [[
                'group'      => $group,
                'key'        => $key,
                'value'      => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => time(),
            ]],
            uniqueBy: ['group', 'key'],
            update:   ['value', 'updated_at'],
        );

        SettingsOverrides::invalidate();

        return Setting::where('group', $group)->where('key', $key)->firstOrFail();
    }

    /**
     * Remove one override, returning how many rows went.
     */
    public function delete(string $group, string $key): int
    {
        $count = Setting::where('group', $group)->where('key', $key)->delete();

        SettingsOverrides::invalidate();

        return (int) $count;
    }

    /**
     * Merged view keyed as [group => [key => decoded_value, ...]] — used by the Settings UI
     * to populate form defaults.
     *
     * @return array<string, array<string, mixed>>
     */
    public function asMap(): array
    {
        $out = [];
        foreach ($this->all() as $row) {
            $out[$row->group][$row->key] = json_decode((string) $row->value, true);
        }

        return $out;
    }
}
