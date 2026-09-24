<?php

use Illuminate\Support\Facades\Route;

// The Vue SPA owns every path except the API, Sanctum and the health check,
// which are registered separately and take precedence.
Route::view('/{any?}', 'app')
    ->where('any', '^(?!api/|sanctum/|up$).*')
    ->name('spa');
