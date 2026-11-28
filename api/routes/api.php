<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Finance\AccountController;
use App\Http\Controllers\Finance\CategoryController;
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

// POST and PUT are the online writes of the first delivery, until the sync exists.
// No DELETE: records are archived (archived_at).
Route::prefix('v1')->middleware(['authenticated', 'throttle:authenticated'])->group(function (): void {
    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::get('/accounts/{id}', [AccountController::class, 'show'])->whereUuid('id')->name('accounts.show');
    Route::put('/accounts/{id}', [AccountController::class, 'update'])->whereUuid('id')->name('accounts.update');

    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::put('/categories/{id}', [CategoryController::class, 'update'])->whereUuid('id')->name('categories.update');
});
