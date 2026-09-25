<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\AppSetting;

/**
 * The colour schemes retroBITE ships, picked app-wide in Settings → UI.
 *
 * The full palettes live in resources/css/app.css as `[data-scheme]` blocks.
 * This owns the keys, and the logo fills the favicon needs — a file cannot
 * read CSS — which mirror the stylesheet and have to be kept in step with it.
 */
enum ColorScheme: string
{
    case Default = 'default';
    case Phosphor = 'phosphor';
    case Famicom = 'famicom';
    case Dmg = 'dmg';
    case Cobalt = 'cobalt';

    /**
     * The stored scheme, or Default when none is stored or the stored key names
     * a scheme that no longer ships.
     */
    public static function current(): self
    {
        return self::tryFrom((string) AppSetting::get(AppSetting::UI_COLOR_SCHEME)) ?? self::Default;
    }

    /**
     * Human-readable name for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Default => __('Default'),
            self::Phosphor => __('Phosphor'),
            self::Famicom => __('Famicom'),
            self::Dmg => __('DMG'),
            self::Cobalt => __('Cobalt'),
        };
    }

    /**
     * The fills for the logo's two red slots, `--color-logo` and
     * `--color-logo-shade` in the stylesheet.
     *
     * @return array{bright: string, shade: string}
     */
    public function logoColors(): array
    {
        return match ($this) {
            self::Default => ['bright' => '#d0202a', 'shade' => '#8e1218'],
            self::Phosphor => ['bright' => '#4fdc72', 'shade' => '#34914b'],
            self::Famicom => ['bright' => '#d0202a', 'shade' => '#8e1218'],
            self::Dmg => ['bright' => '#9bbc0f', 'shade' => '#667c0a'],
            self::Cobalt => ['bright' => '#5b9cf8', 'shade' => '#3c67a4'],
        };
    }
}
