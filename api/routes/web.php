<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

// The API has no server-rendered pages. The web app lives in ../web.
// Same route Sanctum would register, with a rate limit.
Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show'])
    ->middleware('throttle:csrf')->name('sanctum.csrf-cookie');
