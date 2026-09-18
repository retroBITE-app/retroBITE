<?php

use App\Http\Controllers\Auth\LoginBackdropController;
use App\Http\Controllers\Docs\ArchiveDocController;
use App\Http\Controllers\Docs\DownloadDocController;
use App\Http\Controllers\Docs\ServeMediaController as ServeDocMediaController;
use App\Http\Controllers\Library\ServeMediaController;
use Illuminate\Support\Facades\Route;

// Public on purpose, and the only artwork outside the authed route: the
// sign-in page cannot be behind auth. It takes no path, so it serves a
// backdrop of its own choosing and nothing else.
Route::get('login/backdrop', LoginBackdropController::class)->name('login.backdrop');

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

    // Last in the group on purpose: it claims a top-level segment, so any new
    // fixed route has to be declared above it. The component 404s on a key
    // config does not carry, so /nonsense/games is a miss rather than an empty
    // shelf.
    Route::livewire('{console}/games', 'games.index')
        ->where('console', '[a-z0-9\-]+')
        ->name('consoles.games');
});

require __DIR__.'/settings.php';
