<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\SettingRepository;
use App\Support\Console;

/**
 * Guards the regression that made saving any console setting drop its `folder`,
 * collapsing the console's root onto the shared games directory.
 */
final class SettingsOverrideTest extends DatabaseTestCase
{
    public function test_a_partial_override_preserves_unexposed_fields(): void
    {
        $before = config('consoles.ps2');

        $this->override('consoles', 'ps2', [
            'name'            => 'PS2 renamed',
            'file_extensions' => ['iso', 'bin'],
        ]);

        $after = config('consoles.ps2');

        $this->assertSame('PS2 renamed', $after['name'], 'the override should apply');
        $this->assertSame(['iso', 'bin'], $after['file_extensions']);

        $this->assertSame($before['folder'], $after['folder'], 'folder must survive');
        $this->assertSame($before['cover_aspect'] ?? null, $after['cover_aspect'] ?? null);
        $this->assertSame($before['cover_height'] ?? null, $after['cover_height'] ?? null);
    }

    public function test_a_console_keeps_its_own_path_after_an_override(): void
    {
        $expected = Console::tryFrom('ps2')?->path();

        $this->override('consoles', 'ps2', ['name' => 'PS2 renamed']);

        $this->assertSame($expected, Console::tryFrom('ps2')?->path());
        $this->assertNotSame(
            rtrim((string) config('settings.games_path'), '/') . '/',
            Console::tryFrom('ps2')?->path(),
            'an emptied folder would point the console at the whole library',
        );
    }

    public function test_overriding_one_console_leaves_the_others_alone(): void
    {
        $snes = config('consoles.snes');

        $this->override('consoles', 'ps2', ['name' => 'PS2 renamed']);

        $this->assertSame($snes, config('consoles.snes'));
    }

    public function test_resetting_an_override_restores_the_file_default(): void
    {
        $original = config('consoles.ps2.name');

        $repository = new SettingRepository();
        $repository->upsert('consoles', 'ps2', ['name' => 'PS2 renamed']);
        $this->assertSame('PS2 renamed', config('consoles.ps2.name'));

        $repository->delete('consoles', 'ps2');
        $this->assertSame($original, config('consoles.ps2.name'));
    }

    /**
     * Store a partial override the way SettingsController would.
     */
    private function override(string $group, string $key, array $value): void
    {
        (new SettingRepository())->upsert($group, $key, $value);
    }
}
