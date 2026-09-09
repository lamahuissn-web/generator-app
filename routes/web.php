<?php

use App\Http\Controllers\Collector\AuthController;
use App\Http\Controllers\Collector\DashboardController;
use App\Http\Controllers\Collector\RouteController;
use App\Http\Controllers\Collector\SubscriberController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

// ---- Collector mobile portal -------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/collector/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/collector/login', [AuthController::class, 'login'])->name('collector.login.attempt');
});

Route::middleware(['auth', 'role:collector'])->prefix('collector')->name('collector.')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/routes/{route}', [RouteController::class, 'show'])->name('route.show');

    Route::get('/subscribers/{subscriber}', [SubscriberController::class, 'show'])->name('subscriber.show');
});
