<?php

use App\Models\User;

/**
 * Installable as an app: the manifest is a static file in public/, so what can
 * break is a page that stops linking it, or an icon it names going missing.
 */
it('links the manifest from every page head', function () {
    // An install with no account answers every page with onboarding.
    User::factory()->create();

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false);
});

it('ships a manifest whose icons all exist', function () {
    $manifest = json_decode(File::get(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)
        ->toHaveKeys(['name', 'start_url', 'display', 'icons'])
        ->and($manifest['display'])->toBe('standalone');

    // Chrome wants a 192 and a 512 before it offers to install.
    expect(collect($manifest['icons'])->pluck('sizes'))->toContain('192x192', '512x512');

    foreach ($manifest['icons'] as $icon) {
        expect(public_path(ltrim($icon['src'], '/')))->toBeFile();
    }
});

it('caches the offline page the service worker falls back to', function () {
    $worker = File::get(public_path('sw.js'));

    expect($worker)->toContain("'/offline.html'")
        ->and(public_path('offline.html'))->toBeFile();
});
