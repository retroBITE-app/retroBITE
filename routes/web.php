<?php

use App\Http\Controllers\Docs\ArchiveDocController;
use App\Http\Controllers\Docs\DownloadDocController;
use App\Http\Controllers\Docs\ServeMediaController as ServeDocMediaController;
use App\Http\Controllers\Library\ServeMediaController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Library
    Route::livewire('consoles', 'consoles.index')->name('consoles.index');
    Route::livewire('games', 'games.index')->name('games.index');
    Route::livewire('games/{game}', 'games.show')->name('games.show');
    // The media disk sits outside public/, so this authed route is the only
    // way to a downloaded image.
    Route::get('media/{path}', ServeMediaController::class)->where('path', '.*')->name('media.show');

    // Static landing page for the section that has no behaviour yet. Routed so
    // the sidebar link resolves rather than 404.
    Route::view('builder', 'pages.builder')->name('builder.index');

    // Docs
    Route::livewire('docs', 'docs.index')->name('docs.index');
    Route::get('docs/file/{path}', ServeDocMediaController::class)->where('path', '.*')->name('docs.media');
    Route::get('docs/download/{path}', DownloadDocController::class)->where('path', '.*')->name('docs.download');
    Route::get('docs/archive/{path}', ArchiveDocController::class)->where('path', '.*')->name('docs.archive');
});

require __DIR__.'/settings.php';
