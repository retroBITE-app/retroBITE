<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('resets a password by username', function () {
    $user = User::factory()->create(['username' => 'retrogamer']);

    $this->artisan('user:password', ['user' => 'retrogamer', '--password' => 'NewPass!2345'])
        ->assertSuccessful();

    expect(Hash::check('NewPass!2345', $user->fresh()->password))->toBeTrue();
});

test('resets a password by email', function () {
    $user = User::factory()->create(['username' => 'retrogamer', 'email' => 'player@retrobite.local']);

    $this->artisan('user:password', ['user' => 'player@retrobite.local', '--password' => 'NewPass!2345'])
        ->assertSuccessful();

    expect(Hash::check('NewPass!2345', $user->fresh()->password))->toBeTrue();
});

test('falls back to a partial match when only one user matches', function () {
    $user = User::factory()->create(['username' => 'retrogamer']);

    $this->artisan('user:password', ['user' => 'retro', '--password' => 'NewPass!2345'])
        ->assertSuccessful();

    expect(Hash::check('NewPass!2345', $user->fresh()->password))->toBeTrue();
});

test('fails when no user matches', function () {
    $this->artisan('user:password', ['user' => 'ghost', '--password' => 'NewPass!2345'])
        ->assertFailed();
});

test('rejects a password that fails validation', function () {
    $user = User::factory()->create(['username' => 'retrogamer']);
    $original = $user->password;

    $this->artisan('user:password', ['user' => 'retrogamer', '--password' => 'abc'])
        ->assertFailed();

    expect($user->fresh()->password)->toBe($original);
});
