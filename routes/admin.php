<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\SubscriberController;
use Illuminate\Support\Facades\Route;

/*
 | The panel. Built at /manage while the Filament panel still owns /admin; when this
 | reaches parity the prefix becomes 'admin' and the Filament provider is dropped.
 | Route names keep the `admin.` prefix either way, so nothing else has to change.
 */

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
});

Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::prefix('subscribers')->name('subscribers.')->group(function () {
        Route::get('/', [SubscriberController::class, 'index'])->name('index');
        Route::get('/create', [SubscriberController::class, 'create'])->name('create');
        Route::get('/{subscriber}', [SubscriberController::class, 'show'])->name('show');
        Route::get('/{subscriber}/edit', [SubscriberController::class, 'edit'])->name('edit');
    });
});
