<?php

use App\Http\Controllers\Docs\ArchiveDocController;
use App\Http\Controllers\Docs\DownloadDocController;
use App\Http\Controllers\Docs\ServeMediaController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Static landing pages for sections that have no behaviour yet. Routed so
    // the sidebar links resolve rather than 404.
    Route::view('consoles', 'pages.consoles')->name('consoles.index');
    Route::view('builder', 'pages.builder')->name('builder.index');

    // Docs
    Route::livewire('docs', 'docs.index')->name('docs.index');
    Route::get('docs/file/{path}', ServeMediaController::class)->where('path', '.*')->name('docs.media');
    Route::get('docs/download/{path}', DownloadDocController::class)->where('path', '.*')->name('docs.download');
    Route::get('docs/archive/{path}', ArchiveDocController::class)->where('path', '.*')->name('docs.archive');
});

require __DIR__.'/settings.php';
