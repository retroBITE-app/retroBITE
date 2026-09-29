<?php

use App\Models\User;

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'login' => $user->email,
        'password' => 'password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'login' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrorsIn('login');

    $this->assertGuest();
});

test('users can authenticate using their username', function () {
    $user = User::factory()->create(['username' => 'retrogamer']);

    $response = $this->post(route('login.store'), [
        'login' => 'retrogamer',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can not authenticate with an unknown username', function () {
    User::factory()->create(['username' => 'retrogamer']);

    $this->post(route('login.store'), [
        'login' => 'nobody',
        'password' => 'password',
    ]);

    $this->assertGuest();
});
