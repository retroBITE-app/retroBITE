<?php

use App\Models\Game;
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

test('it hides Needs identifying when every game is identified', function () {
    $this->actingAs(User::factory()->create());
    Game::factory()->forConsole('snes')->matched()->create();

    $this->get(route('dashboard'))->assertOk()->assertDontSee(__('Needs identifying'));
});

test('it lists the games still to identify under Needs identifying', function () {
    $this->actingAs(User::factory()->create());
    Game::factory()->forConsole('snes')->unmatched()->create(['title' => 'Mystery Cart']);

    $this->get(route('dashboard'))->assertOk()->assertSee(__('Needs identifying'))->assertSee('Mystery Cart');
});
