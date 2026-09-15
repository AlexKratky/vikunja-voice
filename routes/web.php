<?php

use App\Http\Controllers\RecordingController;
use App\Http\Middleware\AuthenticateUploadToken;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('recorder'));

Route::post('/api/recordings', [RecordingController::class, 'store'])
    ->middleware(AuthenticateUploadToken::class);

// Web Share Target fallback: the service worker normally intercepts this
// POST (see public/sw.js). If no worker is active, bounce back to the app
// with a marker so it can explain instead of showing a 404.
Route::post('/share-target', fn () => redirect('/?share-target=unavailable', 303));
