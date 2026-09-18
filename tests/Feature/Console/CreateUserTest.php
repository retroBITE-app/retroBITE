<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('creates a user with defaults derived from the username', function () {
    $this->artisan('user:create', ['username' => 'retrogamer', '--password' => 'NewPass!2345'])
        ->assertSuccessful();

    $user = User::where('username', 'retrogamer')->sole();

    expect($user->email)->toBe('retrogamer@retrobite.local')
        ->and($user->name)->toBe('retrogamer')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('NewPass!2345', $user->password))->toBeTrue();
});

test('accepts an explicit email and display name', function () {
    $this->artisan('user:create', [
        'username' => 'retrogamer',
        '--email' => 'player@example.com',
        '--name' => 'Player One',
        '--password' => 'NewPass!2345',
    ])->assertSuccessful();

    $user = User::where('username', 'retrogamer')->sole();

    expect($user->email)->toBe('player@example.com')
        ->and($user->name)->toBe('Player One');
});

test('rejects a duplicate username', function () {
    User::factory()->create(['username' => 'retrogamer']);

    $this->artisan('user:create', [
        'username' => 'retrogamer',
        '--email' => 'free@example.com',
        '--password' => 'NewPass!2345',
    ])->assertFailed();

    expect(User::where('username', 'retrogamer')->count())->toBe(1);
});

test('rejects a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('user:create', [
        'username' => 'retrogamer',
        '--email' => 'taken@example.com',
        '--password' => 'NewPass!2345',
    ])->assertFailed();

    expect(User::where('username', 'retrogamer')->exists())->toBeFalse();
});

test('rejects an invalid email', function () {
    $this->artisan('user:create', [
        'username' => 'retrogamer',
        '--email' => 'not-an-email',
        '--password' => 'NewPass!2345',
    ])->assertFailed();

    expect(User::where('username', 'retrogamer')->exists())->toBeFalse();
});

test('rejects a password that fails validation', function () {
    $this->artisan('user:create', ['username' => 'retrogamer', '--password' => 'abc'])
        ->assertFailed();

    expect(User::where('username', 'retrogamer')->exists())->toBeFalse();
});

test('the created user can authenticate', function () {
    $this->artisan('user:create', ['username' => 'retrogamer', '--password' => 'NewPass!2345'])
        ->assertSuccessful();

    $this->post(route('login.store'), [
        'login' => 'retrogamer',
        'password' => 'NewPass!2345',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});
