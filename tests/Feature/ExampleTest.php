<?php

use App\Models\User;

test('the login screen is served at the root url', function () {
    // An install with no account answers every page with onboarding.
    User::factory()->create();

    $response = $this->get(route('login'));

    $response->assertOk();
});
