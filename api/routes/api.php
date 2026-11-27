<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\Internal\TickController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->middleware('throttle:60,1')->name('health');

Route::post('/internal/tick', TickController::class)->middleware('throttle:10,1')->name('internal.tick');
