<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Static landing pages for sections that have no behaviour yet. Routed so
    // the sidebar links resolve rather than 404.
    Route::view('consoles', 'pages.consoles')->name('consoles.index');
    Route::view('builder', 'pages.builder')->name('builder.index');
    Route::view('docs', 'pages.docs')->name('docs.index');
});

require __DIR__.'/settings.php';
