<?php

use App\Http\Controllers\Portal\PwaController;
use Illuminate\Support\Facades\Route;

/*
| The client PWA's own files (Phase 9c, docs/spec/12, "PWA za klijente"), on
| the portal host, outside the `portal` middleware group: they carry no data,
| need no session and set no cookie. Registered before routes/portal.php,
| whose catch-all would otherwise answer with the SPA page.
|
| The service worker must come from the root to control the whole portal; it
| is built from resources/js/portal/service-worker.js with this build's version.
*/

Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->name('portal.pwa.worker');
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('portal.pwa.manifest');
Route::get('/offline', [PwaController::class, 'offline'])->name('portal.pwa.offline');
