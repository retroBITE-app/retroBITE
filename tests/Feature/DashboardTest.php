<?php

use App\Models\User;
use App\Services\NetworkService;

test('guests are redirected to the login page', function () {
    // An install with no account answers every page with onboarding.
    User::factory()->create();

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('the page does not wait on the share probe', function () {
    $this->mock(NetworkService::class)->shouldNotReceive('status');

    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSeeLivewire('network-shares');
});
