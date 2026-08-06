<?php

use App\Http\Controllers\RecordingController;
use App\Http\Middleware\AuthenticateUploadToken;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('recorder'));

Route::post('/api/recordings', [RecordingController::class, 'store'])
    ->middleware(AuthenticateUploadToken::class);
