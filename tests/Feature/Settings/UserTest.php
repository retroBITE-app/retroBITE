<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('user settings page is displayed', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('user.edit'))->assertOk();
});

test('the pages profile and security used to live at still arrive', function () {
    $this->actingAs(User::factory()->create());

    // Both were linked from the sidebar and the account menu for a while.
    // Asserted on the Location header itself: assertRedirect() normalises a
    // relative destination, and hid /settings/settings/user once already.
    foreach (['/settings', '/settings/profile', '/settings/security'] as $old) {
        expect($this->get($old)->headers->get('Location'))->toBe('/settings/user');
    }
});

test('the page opens without confirming a password first', function () {
    $this->actingAs(User::factory()->create());

    // The password form asks for the current password, which is what authorises
    // a change. A gate in front of the whole screen only cost a prompt before
    // reading your own name.
    $this->get(route('user.edit'))->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('settings.user')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->email)->toEqual('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when email address is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('settings.user')
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('the page renders without two factor when the feature is disabled', function () {
    config(['fortify.features' => []]);

    $this->actingAs(User::factory()->create());

    $this->get(route('user.edit'))
        ->assertOk()
        ->assertSee('Password')
        ->assertDontSee('Manage your passkeys for passwordless sign-in')
        ->assertDontSee('Add a passkey to sign in without a password')
        ->assertDontSee('Two-factor authentication');
});

test('password can be updated', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('settings.user')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasNoErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('settings.user')
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasErrors(['current_password']);
});

test('a profile that will not save keeps the password fields out of it', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($user);

    // Two forms on one screen, saving independently: a rejected email must not
    // cost somebody the password they had half typed, or the reverse.
    Livewire::test('settings.user')
        ->set('current_password', 'password')
        ->set('email', 'taken@example.com')
        ->call('updateProfileInformation')
        ->assertHasErrors(['email'])
        ->assertSet('current_password', 'password');
});
