<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The places in the application a person can go, named once.
 *
 * The settings tabs read their strip from here, and the global search jumps
 * to the same list, so a tab added in one place cannot be missing from the
 * other. Keywords are the other words people reach for — "retroachievements"
 * for the Achievements tab — and are matched, never shown. Labels are
 * translation keys, passed through __() where they are drawn.
 */
final class Navigation
{
    /**
     * The pages the sidebar leads to.
     *
     * @return list<array{label: string, route: string, icon: string, keywords: string}>
     */
    public static function pages(): array
    {
        return [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'squares-2x2', 'keywords' => 'home overview start'],
            ['label' => 'Consoles', 'route' => 'consoles.index', 'icon' => 'puzzle-piece', 'keywords' => 'systems platforms library'],
            ['label' => 'Games', 'route' => 'games.index', 'icon' => 'rectangle-stack', 'keywords' => 'library roms titles'],
            ['label' => 'Conversion', 'route' => 'tools.conversion', 'icon' => 'arrows-right-left', 'keywords' => 'tools convert chd cso rvz iso compress'],
            ['label' => 'Documents', 'route' => 'docs.index', 'icon' => 'book-open', 'keywords' => 'docs documentation help guides manual notes'],
            ['label' => 'Settings', 'route' => 'user.edit', 'icon' => 'adjustments-horizontal', 'keywords' => 'preferences options configuration'],
        ];
    }

    /**
     * The settings tabs, in the order the strip shows them. `active` is the
     * route pattern that underlines the tab.
     *
     * @return list<array{label: string, route: string, active: string, icon: string, keywords: string}>
     */
    public static function settings(): array
    {
        return [
            ['label' => 'User', 'route' => 'user.edit', 'active' => 'user.*', 'icon' => 'user', 'keywords' => 'account profile password email username two-factor 2fa'],
            ['label' => 'UI', 'route' => 'interface.edit', 'active' => 'interface.edit', 'icon' => 'swatch', 'keywords' => 'interface appearance theme colour color scanlines view'],
            ['label' => 'Media', 'route' => 'media.edit', 'active' => 'media.edit', 'icon' => 'photo', 'keywords' => 'artwork covers images region'],
            ['label' => 'Consoles', 'route' => 'console-config.edit', 'active' => 'console-config.edit', 'icon' => 'puzzle-piece', 'keywords' => 'console config extensions folders'],
            ['label' => 'Library', 'route' => 'library.edit', 'active' => 'library.edit', 'icon' => 'archive-box', 'keywords' => 'prune missing roms housekeeping clean'],
            ['label' => 'Scraping', 'route' => 'screenscraper.edit', 'active' => 'screenscraper.edit', 'icon' => 'magnifying-glass', 'keywords' => 'screenscraper metadata identify account quota'],
            ['label' => 'Achievements', 'route' => 'retroachievements.edit', 'active' => 'retroachievements.edit', 'icon' => 'trophy', 'keywords' => 'retroachievements ra api key hardcore progress'],
            ['label' => 'Destinations', 'route' => 'destinations.edit', 'active' => 'destinations.edit', 'icon' => 'server-stack', 'keywords' => 'network share smb nas batocera send transfer'],
            ['label' => 'About', 'route' => 'about.show', 'active' => 'about.show', 'icon' => 'information-circle', 'keywords' => 'version credits licence license github source build'],
        ];
    }
}
