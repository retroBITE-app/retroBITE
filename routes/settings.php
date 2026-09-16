<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', 'settings.appearance')->name('appearance.edit');

    Route::livewire('settings/security', 'settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});
