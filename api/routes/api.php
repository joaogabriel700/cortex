<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Internal\TickController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->middleware('throttle:60,1')->name('health');

Route::post('/internal/tick', TickController::class)->middleware('throttle:10,1')->name('internal.tick');

Route::prefix('v1/auth')->name('auth.')->group(function (): void {
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('login');

    // No tenant transaction: signing out only touches the session.
    Route::post('/logout', [SessionController::class, 'destroy'])
        ->middleware(['auth:sanctum', 'session.mark', 'throttle:authenticated'])->name('logout');

    Route::get('/session', [SessionController::class, 'show'])
        ->middleware(['authenticated', 'throttle:authenticated'])->name('session');
});
