<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/user');

    // Profile and password on one screen. No password.confirm in front of it:
    // changing a password already requires the current one, which is what
    // authorises the change — the gate only cost a prompt before reading your
    // own name. The old URLs redirect, because they have been linked to.
    Route::livewire('settings/user', 'settings.user')->name('user.edit');
    // Leading slash, or the destination resolves against the source's own
    // directory and lands on /settings/settings/user.
    Route::redirect('settings/profile', '/settings/user');
    Route::redirect('settings/security', '/settings/user');

    Route::livewire('settings/media', 'settings.media')->name('media.edit');
});
