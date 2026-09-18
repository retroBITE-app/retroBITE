<?php

use App\Models\AppSetting;

/**
 * The CRT overlay is a stored setting rather than a deployed one, so the switch
 * in Settings → Media has to reach the pages that draw it. The sign-in backdrop
 * is the cheapest of the four to assert: it needs no login and no artwork.
 */
it('draws the scanline overlay only while it is switched on', function () {
    $this->get('/')->assertOk()->assertSee('scanlines', false);

    AppSetting::put(AppSetting::UI_SCANLINES, false);

    $this->get('/')->assertOk()->assertDontSee('scanlines', false);
});
