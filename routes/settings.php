<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/user');
    Route::livewire('settings/user', 'settings.user')->name('user.edit');
    Route::redirect('settings/profile', '/settings/user');
    Route::redirect('settings/security', '/settings/user');
    Route::livewire('settings/media', 'settings.media')->name('media.edit');
    Route::livewire('settings/consoles', 'settings.consoles')->name('console-config.edit');
});
