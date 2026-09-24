<?php

use App\Http\Controllers\Auth\LoginBackdropController;
use App\Http\Controllers\Docs\ArchiveDocController;
use App\Http\Controllers\Docs\DownloadDocController;
use App\Http\Controllers\Docs\ServeMediaController as ServeDocMediaController;
use App\Http\Controllers\Library\ServeMediaController;
use App\Http\Controllers\Library\UploadChunkController;
use App\Models\Game;
use Illuminate\Support\Facades\Route;

// Public on purpose, and the only artwork outside the authed route: the
// sign-in page cannot be behind auth. It takes no path, so it serves a
// backdrop of its own choosing and nothing else.
Route::get('login/backdrop', LoginBackdropController::class)->name('login.backdrop');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Library
    // Addressed downward, the way the library is walked: the consoles, one
    // console's shelf, one game on it. A game is named by its slug within its
    // console — unique there and nowhere else, so two consoles may each hold
    // an "aladdin" — which is why {game} is bound below against the {console}
    // beside it rather than by slug alone.
    Route::livewire('consoles', 'consoles.index')->name('consoles.index');
    Route::livewire('consoles/{console}', 'games.index')
        ->where('console', '[a-z0-9\-]+')
        ->name('consoles.games');
    Route::livewire('consoles/{console}/{game}', 'games.show')
        ->where(['console' => '[a-z0-9\-]+', 'game' => '[a-z0-9\-]+'])
        ->name('games.show');
    Route::bind('game', fn (string $slug, Illuminate\Routing\Route $route): Game => Game::query()
        ->where('console', $route->parameter('console'))
        ->where('slug', $slug)
        ->firstOrFail());

    // Every game, across consoles. Not in the hierarchy: it is a view of the
    // library rather than a place in it.
    Route::livewire('games', 'games.index')->name('games.index');

    // The addresses these used to have, kept so a bookmark still lands. A game
    // was once reached by its id, which says nothing about where it lives.
    Route::get('games/{id}', fn (int $id) => redirect()->route('games.show', Game::findOrFail($id)->routeParameters(), 301))
        ->whereNumber('id');
    // The media disk sits outside public/, so this authed route is the only
    // way to a downloaded image.
    Route::get('media/{path}', ServeMediaController::class)->where('path', '.*')->name('media.show');
    // One chunk of a ROM upload, begun and finished by the shelf's upload
    // modal. Raw bytes in the body, which is why it is a route and not a
    // Livewire call. Not behind the UI toggle: that hides the modal, not this.
    Route::post('uploads/{upload}', UploadChunkController::class)->whereUuid('upload')->name('uploads.chunk');

    // Static landing page for the section that has no behaviour yet. Routed so
    // the sidebar link resolves rather than 404.
    Route::view('builder', 'pages.builder')->name('builder.index');

    // Docs
    Route::livewire('docs', 'docs.index')->name('docs.index');
    Route::get('docs/file/{path}', ServeDocMediaController::class)->where('path', '.*')->name('docs.media');
    Route::get('docs/download/{path}', DownloadDocController::class)->where('path', '.*')->name('docs.download');
    Route::get('docs/archive/{path}', ArchiveDocController::class)->where('path', '.*')->name('docs.archive');

    // The shelf's old address. Last in the group on purpose: it claims a
    // top-level segment, so any new fixed route has to be declared above it.
    Route::get('{console}/games', fn (string $console) => redirect()->route('consoles.games', ['console' => $console], 301))
        ->where('console', '[a-z0-9\-]+');
});

require __DIR__.'/settings.php';
