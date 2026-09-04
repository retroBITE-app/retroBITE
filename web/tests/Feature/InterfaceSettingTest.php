<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\SettingRepository;
use App\Services\SettingsService;
use App\Support\SettingsOverrides;

/**
 * The scanline preference is the first boolean setting, and the first that had
 * to fit the group/item shape the overrides table enforces.
 */
final class InterfaceSettingTest extends DatabaseTestCase
{
    private SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = new SettingsService(new SettingRepository());
    }

    public function test_scanlines_default_to_on(): void
    {
        $this->assertTrue(config('interface.appearance.scanlines'));
    }

    public function test_the_toggle_round_trips_through_config(): void
    {
        $this->settings->save('interface', 'appearance', ['scanlines' => false]);

        $this->assertFalse(
            config('interface.appearance.scanlines'),
            'saving must invalidate the merged config, not just write a row',
        );

        $this->settings->save('interface', 'appearance', ['scanlines' => true]);

        $this->assertTrue(config('interface.appearance.scanlines'));
    }

    public function test_resetting_restores_the_default(): void
    {
        $this->settings->save('interface', 'appearance', ['scanlines' => false]);
        $this->settings->reset('interface', 'appearance');

        $this->assertTrue(config('interface.appearance.scanlines'));
    }

    /**
     * The switch posts a real boolean, but a form-encoded "false" must not read
     * as truthy the way a bare string cast would.
     */
    public function test_a_string_false_is_stored_as_false(): void
    {
        $stored = $this->settings->save('interface', 'appearance', ['scanlines' => 'false']);

        $this->assertFalse($stored['scanlines']);

        SettingsOverrides::invalidate();

        $this->assertFalse(config('interface.appearance.scanlines'));
    }

    public function test_the_group_reaches_the_settings_page_as_a_panel_item(): void
    {
        $group = collect($this->settings->groups())->firstWhere('slug', 'interface');

        $this->assertNotNull($group);
        $this->assertSame('Interface', $group['label']);
        $this->assertSame('bool', $group['schema']['scanlines']['type']);
        $this->assertArrayHasKey('appearance', $group['items']);
    }
}
