<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/user');
    Route::livewire('settings/user', 'settings.user')->name('user.edit');
    Route::redirect('settings/profile', '/settings/user');
    Route::redirect('settings/security', '/settings/user');
    Route::livewire('settings/media', 'settings.media')->name('media.edit');
    Route::livewire('settings/consoles', 'settings.consoles')->name('console-config.edit');
    Route::livewire('settings/screenscraper', 'settings.screenscraper')->name('screenscraper.edit');
    Route::livewire('settings/retroachievements', 'settings.retroachievements')->name('retroachievements.edit');
    Route::redirect('settings/integrations', '/settings/retroachievements');
});
