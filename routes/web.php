<?php

use App\Http\Controllers\RecordingController;
use App\Http\Middleware\AuthenticateUploadToken;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/api/recordings', [RecordingController::class, 'store'])
    ->middleware(AuthenticateUploadToken::class);
